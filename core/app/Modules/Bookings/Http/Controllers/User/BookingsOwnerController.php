<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Http\Controllers\User;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingClosedDay;
use Juwri\Modules\Bookings\Models\BookingDepositRefund;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Models\BookingLine;
use Juwri\Modules\Bookings\Models\BookingStaff;
use Juwri\Modules\Bookings\Services\BookingMoney;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\BookingSettings;
use Juwri\Modules\Bookings\Services\BookingSetup;
use Juwri\Modules\Bookings\Services\DepositRefunds;
use Juwri\Modules\Bookings\Services\DepositService;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Bookings\Services\SlotEngine;

/**
 * P3-10: owner screens of the booking engine — bookings list (with the D13 setup checklist), staff, services,
 * weekly hours + breaks + closed days, and settings. Every read and write is scoped to the current store
 * (ResolvesCurrentStore: 403 for a store the owner does not control) and every id is looked up WITHIN that
 * store (404 otherwise), so one store can never read or change another's rows.
 */
class BookingsOwnerController extends Controller
{
    use ResolvesCurrentStore;

    private const TIME = 'regex:/^([01]\d|2[0-3]):[0-5][05]$|^24:00$/';

    public function index(Request $request): View
    {
        $store = $this->currentRestaurant($request);
        $tz = store_tz((int) $store->id);
        $today = CarbonImmutable::now($tz)->startOfDay()->utc();

        $upcoming = Booking::query()->where('store_id', $store->id)
            ->whereIn('status', ['pending_payment', 'confirmed', 'pending_approval'])
            ->where('starts_at', '>=', $today)->with(['lines', 'staff'])->orderBy('starts_at')->limit(200)->get();
        $late = Booking::query()->where('store_id', $store->id)->where('deposit_status', 'received_late')
            ->with(['lines', 'staff'])->orderByDesc('updated_at')->limit(50)->get();
        $refunds = BookingDepositRefund::query()->where('store_id', $store->id)->where('status', 'pending')
            ->with(['booking.lines', 'booking.staff'])->orderBy('created_at')->limit(50)->get()
            ->filter(fn (BookingDepositRefund $r) => $r->booking instanceof Booking)
            ->map(fn (BookingDepositRefund $r) => $this->row($r->booking, $tz) + [
                'refund_error' => (string) $r->last_error,
                'refund_attempts' => $r->attempts,
            ])->values();
        $past = Booking::query()->where('store_id', $store->id)
            ->where(fn ($q) => $q->where('starts_at', '<', $today)->orWhereIn('status', ['cancelled', 'completed', 'no_show']))
            ->where('deposit_status', '!=', 'received_late')
            ->with(['lines', 'staff'])->orderByDesc('starts_at')->limit(50)->get();

        return view('owner.bookings.index', [
            'store' => $store,
            'stores' => $this->stores(),
            'checklist' => BookingSetup::checklist($store),
            'open' => BookingSetup::isOpen($store),
            'upcoming' => $upcoming->map(fn (Booking $b) => $this->row($b, $tz)),
            'late' => $late->map(fn (Booking $b) => $this->row($b, $tz)),
            'refunds' => $refunds,
            'past' => $past->map(fn (Booking $b) => $this->row($b, $tz)),
            'storefrontUrl' => route('bookings.start', ['slug' => $store->slug]),
        ]);
    }

    public function action(Request $request, int $booking, BookingService $bookings, DepositService $deposits, HoldService $holds, SlotEngine $engine): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $row = Booking::query()->where('store_id', $store->id)->whereKey($booking)->firstOrFail();
        $data = $request->validate(['do' => ['required', Rule::in(['complete', 'no_show', 'cancel', 'refund_late', 'accept_late', 'retry_refund'])]]);

        try {
            $message = match ($data['do']) {
                'complete' => tap(___('Marked as done.'), fn () => $bookings->complete($row)),
                'no_show' => tap(___('Marked as no-show.'), fn () => $bookings->noShow($row)),
                'cancel' => match ($bookings->cancel($row, 'store')) {
                    'refunded' => DepositRefunds::wentBack($row) // P3-14 (lane C): automatic refunds through the gateway
                        ? ___('Booking cancelled. The deposit was refunded to the customer\'s payment method.')
                        : ___('Booking cancelled. The deposit refund is recorded: send it back in your payment provider.'),
                    'kept' => ___('Booking cancelled. The deposit is kept (past the cancellation deadline, or deposit refunds are off).'),
                    'refund_pending' => ___('Booking cancelled, but the deposit refund was refused. It is listed under "Deposit refunds to retry".'),
                    default => ___('Booking cancelled.'),
                },
                // lane C: a refused deposit refund, sent again through the same guarded refund
                'retry_refund' => $this->retried($deposits->retryRefund($row), $row),
                'refund_late' => $deposits->refund($row, 'late_payment')
                    ? (DepositRefunds::wentBack($row)
                        ? ___('Late payment refunded to the customer\'s payment method.')
                        : ___('Late payment refund recorded: send it back in your payment provider.'))
                    : ___('Nothing to refund.'),
                'accept_late' => $deposits->acceptLate($row, $holds, $engine) === 'confirmed'
                    ? ___('Payment accepted. The original time was still free, so the booking is confirmed.')
                    : ___('Payment accepted. The time is no longer free: agree a new time with the customer or refund.'),
                default => abort(422),
            };
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return back()->with('success', $message);
    }

    // ---- staff ------------------------------------------------------------------------------------------------

    public function staff(Request $request): View
    {
        $store = $this->currentRestaurant($request);

        return view('owner.bookings.staff', [
            'store' => $store,
            'stores' => $this->stores(),
            'staff' => BookingStaff::query()->where('store_id', $store->id)->with('services:id')->orderBy('position')->orderBy('id')->get(),
            'services' => BookableService::query()->where('store_id', $store->id)->orderBy('position')->orderBy('id')->get(['id', 'name', 'active']),
        ]);
    }

    public function saveStaff(Request $request, ?int $staff = null): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'role' => ['nullable', 'string', 'max:60'],
            'photo_url' => ['nullable', 'url:https', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'services' => ['nullable', 'array'],
            'services.*' => ['integer'],
        ]);
        $serviceIds = BookableService::query()->where('store_id', $store->id)->whereIn('id', $data['services'] ?? [])->pluck('id')->all();

        DB::transaction(function () use ($store, $staff, $data, $serviceIds) {
            $row = $staff === null
                ? new BookingStaff(['store_id' => (int) $store->id])
                : BookingStaff::query()->where('store_id', $store->id)->whereKey($staff)->firstOrFail();
            $row->fill([
                'name' => $data['name'],
                'role' => $data['role'] ?? null,
                'photo_url' => $data['photo_url'] ?? null,
                'active' => $staff === null ? true : (bool) ($data['active'] ?? false),
                'position' => (int) ($data['position'] ?? $row->position ?? 0),
            ])->save();
            $row->services()->syncWithPivotValues($serviceIds, ['store_id' => (int) $store->id, 'account_id' => (int) $store->account_id]);
        });

        return back()->with('success', ___('Staff saved.'));
    }

    public function deleteStaff(Request $request, int $staff): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $row = BookingStaff::query()->where('store_id', $store->id)->whereKey($staff)->firstOrFail();
        if (Booking::query()->where('staff_id', $row->id)->exists() || DB::table('booking_holds')->where('staff_id', $row->id)->exists()) {
            // never delete booking history: someone who was booked is switched off instead
            $row->forceFill(['active' => false])->save();

            return back()->with('success', ___('This staff member has bookings, so they were turned off instead of removed.'));
        }
        $row->delete();

        return back()->with('success', ___('Staff removed.'));
    }

    // ---- services ---------------------------------------------------------------------------------------------

    public function services(Request $request): View
    {
        $store = $this->currentRestaurant($request);
        $currency = store_currency((int) $store->id);
        $rows = BookableService::query()->where('store_id', $store->id)->orderBy('position')->orderBy('id')->get();

        return view('owner.bookings.services', [
            'store' => $store,
            'stores' => $this->stores(),
            'currency' => $currency,
            'atHome' => BookingSettings::for((int) $store->id)->atHome,
            'services' => $rows->map(fn (BookableService $s) => [
                'model' => $s,
                'price' => Money::ofMinor($s->price_minor, $s->currency)->major(),
                'deposit_amount' => $s->deposit_type === 'amount' ? Money::ofMinor($s->deposit_value, $s->currency)->major() : '',
                'deposit_percent' => $s->deposit_type === 'percent' ? $s->deposit_value : '',
                'meta' => BookingMoney::duration($s->duration_min).' · '.BookingMoney::format($s->price_minor, $s->currency, (int) $store->id),
                'deposit' => $s->depositMinor() > 0 ? BookingMoney::format($s->depositMinor(), $s->currency, (int) $store->id) : null,
            ]),
        ]);
    }

    public function saveService(Request $request, ?int $service = null): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $five = fn (string $attr, mixed $v, \Closure $fail) => (int) $v % SlotEngine::CELL === 0 ? null : $fail(___('Use steps of 5 minutes.'));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_min' => ['required', 'integer', 'min:5', 'max:720', $five],
            'buffer_before_min' => ['nullable', 'integer', 'min:0', 'max:240', $five],
            'buffer_after_min' => ['nullable', 'integer', 'min:0', 'max:240', $five],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'deposit_type' => ['required', Rule::in(['none', 'amount', 'percent'])],
            'deposit_amount' => ['nullable', 'required_if:deposit_type,amount', 'numeric', 'min:0', 'max:9999999'],
            'deposit_percent' => ['nullable', 'required_if:deposit_type,percent', 'integer', 'min:1', 'max:100'],
            'venue' => ['nullable', 'boolean'],
            'at_home' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
        if (empty($data['venue']) && empty($data['at_home'])) {
            return back()->withErrors(['venue' => ___('Choose where the service is done.')])->withInput();
        }

        $row = $service === null
            ? new BookableService(['store_id' => (int) $store->id, 'currency' => store_currency((int) $store->id)])
            : BookableService::query()->where('store_id', $store->id)->whereKey($service)->firstOrFail();
        $currency = (string) $row->currency;
        $depositValue = match ($data['deposit_type']) {
            'amount' => Money::minorFromMajor((string) $data['deposit_amount'], $currency),
            'percent' => (int) $data['deposit_percent'],
            default => 0,
        };
        $row->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'duration_min' => (int) $data['duration_min'],
            'buffer_before_min' => (int) ($data['buffer_before_min'] ?? 0),
            'buffer_after_min' => (int) ($data['buffer_after_min'] ?? 0),
            'price_minor' => Money::minorFromMajor((string) $data['price'], $currency),
            'deposit_type' => $data['deposit_type'],
            'deposit_value' => $depositValue,
            'venue' => (bool) ($data['venue'] ?? false),
            'at_home' => (bool) ($data['at_home'] ?? false),
            'active' => $service === null ? true : (bool) ($data['active'] ?? false),
            'position' => (int) ($data['position'] ?? $row->position ?? 0),
        ])->save();

        return back()->with('success', ___('Service saved.'));
    }

    public function deleteService(Request $request, int $service): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $row = BookableService::query()->where('store_id', $store->id)->whereKey($service)->firstOrFail();
        if (BookingLine::query()->where('service_id', $row->id)->exists()) {
            $row->forceFill(['active' => false])->save();

            return back()->with('success', ___('This service has bookings, so it was turned off instead of removed.'));
        }
        $row->delete();

        return back()->with('success', ___('Service removed.'));
    }

    // ---- hours ------------------------------------------------------------------------------------------------

    public function hours(Request $request): View
    {
        $store = $this->currentRestaurant($request);
        $staff = BookingStaff::query()->where('store_id', $store->id)->orderBy('position')->orderBy('id')->get(['id', 'name']);
        $staffId = $request->integer('staff') ?: null;
        if ($staffId !== null && ! $staff->contains('id', $staffId)) {
            abort(404);
        }
        $rows = BookingHour::query()->where('store_id', $store->id)
            ->when($staffId, fn ($q) => $q->where('staff_id', $staffId), fn ($q) => $q->whereNull('staff_id'))->get();

        $week = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $day) {
            $open = $rows->first(fn ($r) => $r->weekday === $day && $r->kind === 'open');
            $break = $rows->first(fn ($r) => $r->weekday === $day && $r->kind === 'break');
            $week[] = [
                'day' => $day,
                'label' => CarbonImmutable::now()->startOfWeek(CarbonImmutable::SUNDAY)->addDays($day)->translatedFormat('l'),
                'open' => $open !== null,
                'starts' => $open->starts_local ?? '09:00',
                'ends' => $open->ends_local ?? '17:00',
                'break_starts' => $break->starts_local ?? '',
                'break_ends' => $break->ends_local ?? '',
            ];
        }

        return view('owner.bookings.hours', [
            'store' => $store,
            'stores' => $this->stores(),
            'staff' => $staff,
            'staffId' => $staffId,
            'ownHours' => $rows->isNotEmpty(),
            'week' => $week,
            'closed' => BookingClosedDay::query()->where('store_id', $store->id)
                ->whereDate('date', '>=', CarbonImmutable::now(store_tz((int) $store->id))->toDateString())
                ->orderBy('date')->get()
                ->map(fn ($d) => ['id' => $d->id, 'date' => $d->date->toDateString(), 'note' => $d->note, 'who' => $d->staff_id ? (string) $staff->firstWhere('id', $d->staff_id)?->name : ___('Everyone')]),
        ]);
    }

    public function saveHours(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate([
            'staff_id' => ['nullable', 'integer'],
            'use_store_hours' => ['nullable', 'boolean'],
            'days' => ['nullable', 'array'],
            'days.*.open' => ['nullable', 'boolean'],
            'days.*.starts' => ['nullable', self::TIME],
            'days.*.ends' => ['nullable', self::TIME],
            'days.*.break_starts' => ['nullable', self::TIME],
            'days.*.break_ends' => ['nullable', self::TIME],
        ]);
        $staffId = isset($data['staff_id']) ? (int) $data['staff_id'] : null;
        if ($staffId !== null) {
            BookingStaff::query()->where('store_id', $store->id)->whereKey($staffId)->firstOrFail();
        }

        $rows = [];
        if (! ($staffId !== null && ! empty($data['use_store_hours']))) {
            foreach ($data['days'] ?? [] as $day => $d) {
                if (! is_numeric($day) || (int) $day < 0 || (int) $day > 6 || empty($d['open'])) {
                    continue;
                }
                $starts = (string) ($d['starts'] ?? '');
                $ends = (string) ($d['ends'] ?? '');
                if ($starts === '' || $ends === '' || strcmp($ends, $starts) <= 0) {
                    return back()->withErrors(['days' => ___('Each open day needs a closing time after its opening time.')])->withInput();
                }
                $rows[] = ['weekday' => (int) $day, 'kind' => 'open', 'starts_local' => $starts, 'ends_local' => $ends];
                $bs = (string) ($d['break_starts'] ?? '');
                $be = (string) ($d['break_ends'] ?? '');
                if ($bs !== '' || $be !== '') {
                    if ($bs === '' || $be === '' || strcmp($be, $bs) <= 0 || strcmp($bs, $starts) < 0 || strcmp($be, $ends) > 0) {
                        return back()->withErrors(['days' => ___('A break must start and end inside the opening hours.')])->withInput();
                    }
                    $rows[] = ['weekday' => (int) $day, 'kind' => 'break', 'starts_local' => $bs, 'ends_local' => $be];
                }
            }
        }

        DB::transaction(function () use ($store, $staffId, $rows) {
            BookingHour::query()->where('store_id', $store->id)
                ->when($staffId !== null, fn ($q) => $q->where('staff_id', $staffId), fn ($q) => $q->whereNull('staff_id'))->delete();
            foreach ($rows as $r) {
                BookingHour::query()->create($r + ['store_id' => (int) $store->id, 'staff_id' => $staffId]);
            }
        });

        return back()->with('success', ___('Hours saved.'));
    }

    public function addClosedDay(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);
        $staffId = isset($data['staff_id']) && (int) $data['staff_id'] > 0 ? (int) $data['staff_id'] : null;
        if ($staffId !== null) {
            BookingStaff::query()->where('store_id', $store->id)->whereKey($staffId)->firstOrFail();
        }
        BookingClosedDay::query()->create(['store_id' => (int) $store->id, 'staff_id' => $staffId, 'date' => $data['date'], 'note' => $data['note'] ?? null]);

        return back()->with('success', ___('Closed day added. Existing bookings on that day stay: move or cancel them if needed.'));
    }

    public function deleteClosedDay(Request $request, int $day): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        BookingClosedDay::query()->where('store_id', $store->id)->whereKey($day)->firstOrFail()->delete();

        return back()->with('success', ___('Closed day removed.'));
    }

    // ---- settings ---------------------------------------------------------------------------------------------

    public function settings(Request $request): View
    {
        $store = $this->currentRestaurant($request);

        return view('owner.bookings.settings', [
            'store' => $store,
            'stores' => $this->stores(),
            's' => BookingSettings::for((int) $store->id),
            'currency' => store_currency((int) $store->id),
            'noShowFee' => Money::ofMinor(BookingSettings::for((int) $store->id)->noShowFeeMinor, store_currency((int) $store->id))->major(),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate([
            'slot_interval' => ['required', 'integer', Rule::in(BookingSettings::INTERVALS)],
            'lead_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'max_days' => ['required', 'integer', 'min:1', 'max:365'],
            'cancel_window_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'deposit_refunds' => ['nullable', 'boolean'],
            'no_show_fee' => ['nullable', 'boolean'],
            'no_show_fee_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'staff_choice' => ['nullable', 'boolean'],
            'at_home' => ['nullable', 'boolean'],
            'at_home_radius_km' => ['nullable', 'integer', 'min:1', 'max:500'],
            'travel_buffer_min' => ['nullable', 'integer', 'min:0', 'max:240'],
            'base_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'base_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'reminder_email' => ['nullable', 'boolean'],
            'reminder_sms' => ['nullable', 'boolean'],
            'reminder_whatsapp' => ['nullable', 'boolean'],
            'policy_note' => ['nullable', 'string', 'max:500'],
        ]);
        if (! empty($data['at_home']) && ($data['base_lat'] ?? null) === null) {
            return back()->withErrors(['base_lat' => ___('Add the location visits start from, so the service area can be checked.')])->withInput();
        }
        if ((int) ($data['travel_buffer_min'] ?? 0) % SlotEngine::CELL !== 0) {
            return back()->withErrors(['travel_buffer_min' => ___('Use steps of 5 minutes.')])->withInput();
        }

        StoreSettings::setMany((int) $store->id, [
            'booking_slot_interval' => (int) $data['slot_interval'],
            'booking_lead_minutes' => (int) $data['lead_minutes'],
            'booking_max_days' => (int) $data['max_days'],
            'booking_cancel_window_hours' => (int) $data['cancel_window_hours'],
            'booking_deposit_refunds' => (bool) ($data['deposit_refunds'] ?? false),
            'booking_no_show_fee' => (bool) ($data['no_show_fee'] ?? false),
            'booking_no_show_fee_minor' => Money::minorFromMajor((string) ($data['no_show_fee_amount'] ?? 0), store_currency((int) $store->id)),
            'booking_staff_choice' => (bool) ($data['staff_choice'] ?? false),
            'booking_at_home' => (bool) ($data['at_home'] ?? false),
            'booking_at_home_radius_km' => (int) ($data['at_home_radius_km'] ?? 10),
            'booking_travel_buffer_min' => (int) ($data['travel_buffer_min'] ?? 0),
            'booking_base_lat' => isset($data['base_lat']) ? (string) $data['base_lat'] : '',
            'booking_base_lng' => isset($data['base_lng']) ? (string) $data['base_lng'] : '',
            'booking_reminder_email' => (bool) ($data['reminder_email'] ?? false),
            'booking_reminder_sms' => (bool) ($data['reminder_sms'] ?? false),
            'booking_reminder_whatsapp' => (bool) ($data['reminder_whatsapp'] ?? false),
            'booking_policy_note' => (string) ($data['policy_note'] ?? ''),
        ]);

        return back()->with('success', ___('Booking settings saved.'));
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    /** @param  array{outcome: string, message: string|null}  $result */
    private function retried(array $result, Booking $row): string
    {
        return match ($result['outcome']) {
            'refunded' => DepositRefunds::wentBack($row)
                ? ___('Deposit refunded to the customer\'s payment method.')
                : ___('Deposit refund recorded: send it back in your payment provider.'),
            'pending' => ___('The refund was refused again: :reason', ['reason' => (string) $result['message']]),
            default => ___('Nothing to refund.'),
        };
    }

    /** @return Collection<int, Store> */
    private function stores()
    {
        return Store::query()->whereIn('id', $this->storeIds())->get(['id', 'title', 'slug']);
    }

    /** @return array<string, mixed> */
    private function row(Booking $b, string $tz): array
    {
        $start = $b->starts_at->copy()->setTimezone($tz);

        return [
            'id' => $b->id,
            'number' => $b->number,
            'status' => $b->status,
            'deposit_status' => $b->deposit_status,
            'when' => $start->translatedFormat('D j M').' · '.$start->format('H:i').'–'.$b->ends_at->copy()->setTimezone($tz)->format('H:i'),
            'customer' => $b->customer_name,
            'phone' => $b->phone,
            'note' => $b->note,
            'staff' => (string) $b->staff?->name,
            'services' => $b->lines->pluck('name')->implode(', '),
            'location' => $b->location,
            'address' => $b->address,
            'total' => BookingMoney::format($b->total_minor, $b->currency, (int) $b->store_id),
            'deposit' => $b->deposit_minor > 0 ? BookingMoney::format($b->deposit_minor, $b->currency, (int) $b->store_id) : null,
            'no_show_fee' => $b->no_show_fee_minor > 0 ? BookingMoney::format($b->no_show_fee_minor, $b->currency, (int) $b->store_id) : null,
        ];
    }
}
