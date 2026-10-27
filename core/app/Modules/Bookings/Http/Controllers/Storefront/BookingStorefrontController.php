<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Http\Controllers\Storefront;

use App\Services\Verticals\VerticalRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Contracts\BookingPageRenderer;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingDepositRefund;
use Juwri\Modules\Bookings\Models\BookingHold;
use Juwri\Modules\Bookings\Models\BookingStaff;
use Juwri\Modules\Bookings\Services\BookingCalendar;
use Juwri\Modules\Bookings\Services\BookingMoney;
use Juwri\Modules\Bookings\Services\BookingPages;
use Juwri\Modules\Bookings\Services\BookingQuote;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\BookingSettings;
use Juwri\Modules\Bookings\Services\BookingSetup;
use Juwri\Modules\Bookings\Services\DepositService;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Bookings\Services\ManageLink;
use Juwri\Modules\Bookings\Services\SlotEngine;
use Juwri\Modules\Payments\Contracts\CustomerGateways;
use Juwri\Modules\Payments\Contracts\Gateways;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * P3-10: the customer booking flow on the existing storefront — a plain, server-rendered page set (the `book` kit
 * theme replaces the look in P3-11): services → staff, day and time → 10-minute hold → details → deposit through the
 * store's gateway (or straight to confirmed) → the booking page (signed manage link: add to calendar, reschedule,
 * cancel within the policy). Store-local times everywhere, with the time zone named.
 *
 * Holds belong to the browser session that made them (their token lives only in the session); a booking is reached
 * only through its signed manage token, never by number or id.
 */
class BookingStorefrontController extends Controller
{
    public function __construct(private SlotEngine $engine, private HoldService $holds, private BookingService $bookings, private BookingPages $pages) {}

    public function start(string $slug, Request $request): View|RedirectResponse
    {
        $store = $this->store($slug);
        if ($this->renderer()?->renders($store)) {
            return redirect()->route('publicView', ['slug' => $store->slug]); // the theme's own page lists the services
        }
        $settings = BookingSettings::for((int) $store->id);
        $services = BookableService::query()->where('store_id', $store->id)->where('active', true)->orderBy('position')->orderBy('id')->get();

        return view('shop.bookings.start', $this->base($store, $settings) + [
            'open' => BookingSetup::isOpen($store),
            'services' => $services->map(fn (BookableService $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'meta' => BookingMoney::duration($s->duration_min).' · '.BookingMoney::format($s->price_minor, $s->currency, (int) $store->id),
                'venue' => $s->venue,
                'at_home' => $s->at_home,
            ]),
        ]);
    }

    public function times(string $slug, Request $request): View|BaseResponse|RedirectResponse
    {
        $store = $this->store($slug);
        $settings = BookingSettings::for((int) $store->id);
        if (! BookingSetup::isOpen($store)) {
            return redirect()->route('bookings.start', ['slug' => $store->slug]);
        }
        $location = $request->query('location') === 'at_home' ? 'at_home' : 'venue';
        try {
            $services = $this->engine->services((int) $store->id, (array) $request->query('services', []), $location);
            if ($location === 'at_home') {
                $this->holds->assertInArea($settings, $this->coords($request));
            }
        } catch (BookingException $e) {
            return redirect()->route('bookings.start', ['slug' => $store->slug])->withErrors(['booking' => $e->getMessage()]);
        }

        $data = $this->base($store, $settings) + $this->timesData($request, $store, $settings, $services, $location) + [
            'action' => route('bookings.hold', ['slug' => $store->slug]),
            'reschedule' => null,
        ];

        return $this->themed($store, 'times', $data) ?? view('shop.bookings.times', $data);
    }

    public function hold(string $slug, Request $request): RedirectResponse
    {
        $store = $this->store($slug);
        $data = $request->validate([
            'services' => ['required', 'array', 'min:1', 'max:4'],
            'services.*' => ['required'],
            'staff_id' => ['nullable', 'string', 'max:40'],
            'starts_at' => ['required', 'integer'],
            'location' => ['nullable', 'in:venue,at_home'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
        ]);
        try {
            $made = $this->holds->hold($store, $data['services'], $this->staffId($store, $data['staff_id'] ?? null),
                CarbonImmutable::createFromTimestampUTC((int) $data['starts_at']), (string) ($data['location'] ?? 'venue'),
                $this->holderKey($request), $this->coords($request));
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }
        $request->session()->put('bookings.holds.'.$made['hold']->public_id, $made['token']);

        $where = $made['hold']->location === 'at_home' ? array_filter($this->coords($request), fn ($v) => $v !== null) : [];

        return redirect()->route('bookings.details', ['slug' => $store->slug, 'hold' => $made['hold']->public_id] + $where);
    }

    public function details(string $slug, string $hold, Request $request): View|BaseResponse|RedirectResponse
    {
        $store = $this->store($slug);
        $settings = BookingSettings::for((int) $store->id);
        $row = $this->sessionHold($store, $hold, $request);
        if ($row === null || ! $row->isLive()) {
            return redirect()->route('bookings.start', ['slug' => $store->slug])->withErrors(['booking' => BookingException::holdExpired()->getMessage()]);
        }
        $services = $this->engine->services((int) $store->id, $row->service_ids, $row->location);
        $quote = BookingQuote::for((int) $store->id, $services);
        $deposit = $quote['deposit_minor'];
        $total = $quote['total_minor'];
        $currency = $quote['currency'];
        $deadline = CarbonImmutable::instance($row->starts_at)->subHours($settings->cancelWindowHours);

        $data = $this->base($store, $settings) + [
            'hold' => $row,
            'summary' => $this->summary($store, $settings, $row->starts_at, $row->ends_at, $services->pluck('name')->all(), BookingStaff::query()->find($row->staff_id)),
            'total' => BookingMoney::format($total, $currency, (int) $store->id),
            'deposit' => $deposit > 0 ? BookingMoney::format($deposit, $currency, (int) $store->id) : null,
            'dueLater' => $deposit > 0 ? BookingMoney::format($total - $deposit, $currency, (int) $store->id) : null,
            'policy' => $this->policy($settings, $deadline, $deposit > 0),
            'expiresAt' => $row->expires_at->copy()->setTimezone($settings->timezone)->format('H:i'),
            'clientKey' => (string) Str::uuid7(),
            'atHome' => $row->location === 'at_home',
            'coords' => ['lat' => $request->query('lat'), 'lng' => $request->query('lng')],
            'quote' => $quote,
            'services' => $services,
            'staff' => BookingStaff::query()->find($row->staff_id),
        ];

        return $this->themed($store, 'details', $data) ?? view('shop.bookings.details', $data);
    }

    public function release(string $slug, string $hold, Request $request): RedirectResponse
    {
        $store = $this->store($slug);
        $row = $this->sessionHold($store, $hold, $request);
        if ($row !== null) {
            $this->holds->release($row);
            $request->session()->forget('bookings.holds.'.$row->public_id);
        }

        return redirect()->route('bookings.start', ['slug' => $store->slug]);
    }

    public function book(string $slug, Request $request): RedirectResponse
    {
        $store = $this->store($slug);
        $data = $request->validate([
            'hold' => ['required', 'uuid'],
            'client_key' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'note' => ['nullable', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'remind_email' => ['nullable', 'boolean'],
            'remind_sms' => ['nullable', 'boolean'],
            'remind_whatsapp' => ['nullable', 'boolean'],
        ]);
        $token = (string) $request->session()->get('bookings.holds.'.$data['hold'], '');
        try {
            $booking = $this->bookings->book($store, $data['hold'], $token, [
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'note' => $data['note'] ?? null,
                'address' => $data['address'] ?? null,
                'lat' => isset($data['lat']) ? (float) $data['lat'] : null,
                'lng' => isset($data['lng']) ? (float) $data['lng'] : null,
                'remind_email' => (bool) ($data['remind_email'] ?? false),
                'remind_sms' => (bool) ($data['remind_sms'] ?? false),
                'remind_whatsapp' => (bool) ($data['remind_whatsapp'] ?? false),
                'source' => 'web',
            ], $data['client_key'] ?? null);
        } catch (BookingException $e) {
            return $e->errorCode === 'invalid_request' || $e->errorCode === 'outside_area'
                ? back()->withErrors(['booking' => $e->getMessage()])->withInput()
                : redirect()->route('bookings.start', ['slug' => $store->slug])->withErrors(['booking' => $e->getMessage()]);
        }
        $request->session()->forget('bookings.holds.'.$data['hold']);
        $token = ManageLink::token($booking);

        return $booking->status === 'pending_payment'
            ? redirect()->route('bookings.pay', ['slug' => $store->slug, 'token' => $token])
            : redirect()->route('bookings.manage', ['slug' => $store->slug, 'token' => $token]);
    }

    public function pay(string $slug, string $token): View|BaseResponse|RedirectResponse
    {
        $store = $this->store($slug);
        $booking = $this->managed($store, $token);
        if ($booking->status !== 'pending_payment') {
            return redirect()->route('bookings.manage', ['slug' => $store->slug, 'token' => $token]);
        }
        $settings = BookingSettings::for((int) $store->id);

        $data = $this->base($store, $settings) + [
            'token' => $token,
            'booking' => $booking,
            'deposit' => BookingMoney::format($booking->deposit_minor, $booking->currency, (int) $store->id),
            'expiresAt' => $booking->payment_expires_at?->copy()->setTimezone($settings->timezone)->format('H:i'),
            'gateways' => app(CustomerGateways::class)->forStore((int) $store->id),
        ];

        return $this->themed($store, 'pay', $data) ?? view('shop.bookings.pay', $data);
    }

    public function charge(string $slug, string $token, Request $request, DepositService $deposits): BaseResponse
    {
        $store = $this->store($slug);
        $booking = $this->managed($store, $token);
        $data = $request->validate(['payment_method' => ['required', 'string', 'max:64']]);
        $folder = app(CustomerGateways::class)->resolve((int) $store->id, $data['payment_method']);
        if ($folder === null) {
            return back()->withErrors(['payment_method' => ___('Please choose a valid payment method.')]);
        }
        try {
            $transaction = $deposits->start($booking, $folder);
        } catch (BookingException $e) {
            return redirect()->route('bookings.manage', ['slug' => $store->slug, 'token' => $token])->withErrors(['booking' => $e->getMessage()]);
        }
        $controller = app(Gateways::class)->controllerFor($folder);

        return $controller::pay($transaction);
    }

    public function manage(string $slug, string $token): View|BaseResponse
    {
        $store = $this->store($slug);
        $booking = $this->managed($store, $token);
        $settings = BookingSettings::for((int) $store->id);
        $booking->loadMissing(['lines', 'staff']);

        $data = $this->base($store, $settings) + [
            'token' => $token,
            'booking' => $booking,
            'summary' => $this->summary($store, $settings, $booking->starts_at, $booking->ends_at, $booking->lines->pluck('name')->all(), $booking->staff),
            'total' => BookingMoney::format($booking->total_minor, $booking->currency, (int) $store->id),
            'deposit' => $booking->deposit_minor > 0 ? BookingMoney::format($booking->deposit_minor, $booking->currency, (int) $store->id) : null,
            'policy' => $this->policy($settings, CarbonImmutable::instance($booking->cancel_deadline_at), $booking->deposit_minor > 0),
            'canChange' => $booking->status === 'confirmed' && $booking->beforeDeadline(),
            'canCancel' => $booking->isActive(),
            // lane C: a refused deposit refund the owner still has to retry — the customer sees it is being processed
            'refundPending' => BookingDepositRefund::query()->where('store_id', $store->id)->where('booking_id', $booking->id)->where('status', 'pending')->exists(),
            'googleUrl' => BookingCalendar::googleUrl($booking, $store),
            'mapsUrl' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode((string) ($booking->location === 'at_home' ? $booking->address : $store->address)),
        ];

        return $this->themed($store, 'manage', $data) ?? view('shop.bookings.manage', $data);
    }

    public function calendar(string $slug, string $token): Response
    {
        $store = $this->store($slug);
        $booking = $this->managed($store, $token);
        $booking->loadMissing('lines');

        return response(BookingCalendar::ics($booking, $store), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="booking-'.$booking->number.'.ics"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function cancel(string $slug, string $token): RedirectResponse
    {
        $store = $this->store($slug);
        $booking = $this->managed($store, $token);
        try {
            $outcome = $this->bookings->cancel($booking, 'customer');
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return back()->with('success', match ($outcome) {
            'refunded' => ___('Your booking is cancelled. Your deposit will be refunded.'),
            'kept' => ___('Your booking is cancelled. The deposit is not refunded.'),
            'refund_pending' => ___('Your booking is cancelled. Your deposit refund is being processed.'),
            default => ___('Your booking is cancelled.'),
        });
    }

    public function rescheduleTimes(string $slug, string $token, Request $request): View|BaseResponse|RedirectResponse
    {
        $store = $this->store($slug);
        $booking = $this->managed($store, $token);
        $settings = BookingSettings::for((int) $store->id);
        if ($booking->status !== 'confirmed' || ! $booking->beforeDeadline()) {
            return redirect()->route('bookings.manage', ['slug' => $store->slug, 'token' => $token])
                ->withErrors(['booking' => BookingException::deadlinePassed()->getMessage()]);
        }
        $booking->loadMissing('lines');
        $services = $this->engine->services((int) $store->id, $booking->lines->pluck('service_id')->filter()->values()->all(), $booking->location);

        $data = $this->base($store, $settings) + $this->timesData($request, $store, $settings, $services, $booking->location, $booking) + [
            'action' => route('bookings.reschedule', ['slug' => $store->slug, 'token' => $token]),
            'reschedule' => $booking,
            'token' => $token,
        ];

        return $this->themed($store, 'reschedule', $data) ?? view('shop.bookings.times', $data);
    }

    public function reschedule(string $slug, string $token, Request $request): RedirectResponse
    {
        $store = $this->store($slug);
        $booking = $this->managed($store, $token);
        $data = $request->validate(['staff_id' => ['nullable', 'string', 'max:40'], 'starts_at' => ['required', 'integer']]);
        try {
            $made = $this->holds->hold($store, [], $this->staffId($store, $data['staff_id'] ?? null),
                CarbonImmutable::createFromTimestampUTC((int) $data['starts_at']), $booking->location, $this->holderKey($request), [], $booking);
            try {
                $this->bookings->reschedule($booking, (string) $made['hold']->public_id, $made['token']);
            } catch (BookingException $e) {
                $this->holds->release($made['hold']);

                throw $e;
            }
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()->route('bookings.manage', ['slug' => $store->slug, 'token' => $token])
            ->with('success', ___('Your booking is moved. Same price, nothing to pay.'));
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    private function renderer(): ?BookingPageRenderer
    {
        return app()->bound(BookingPageRenderer::class) ? app(BookingPageRenderer::class) : null;
    }

    /** @param  array<string, mixed>  $data */
    private function themed(Store $store, string $page, array $data): ?BaseResponse
    {
        $renderer = $this->renderer();

        return $renderer !== null && $renderer->renders($store) ? $renderer->render($store, $page, $data) : null;
    }

    /** A services store (404 otherwise); whether it takes new bookings is BookingSetup's call, per step. */
    private function store(string $slug): Store
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();
        abort_unless(app(VerticalRegistry::class)->has($store->store_type ?? null, 'service'), 404);

        return $store;
    }

    private function managed(Store $store, string $token): Booking
    {
        $booking = ManageLink::resolve($store, $token);
        abort_unless($booking instanceof Booking, 404);

        return $booking;
    }

    private function sessionHold(Store $store, string $publicId, Request $request): ?BookingHold
    {
        $token = (string) $request->session()->get('bookings.holds.'.$publicId, '');
        if ($token === '' || ! Str::isUuid($publicId)) {
            return null;
        }
        $hold = BookingHold::query()->where('store_id', $store->id)->where('public_id', $publicId)->first();

        return $hold instanceof BookingHold && $hold->tokenMatches($token) ? $hold : null;
    }

    /** A staff member named by id or public id (the theme uses public ids); "any", empty or unknown is null (any staff). */
    private function staffId(Store $store, mixed $value): ?int
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '' || $value === 'any') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        if (! Str::isUuid($value)) {
            return null;
        }

        return BookingStaff::query()->where('store_id', $store->id)->where('public_id', $value)->value('id');
    }

    private function holderKey(Request $request): string
    {
        return hash('sha256', $request->session()->getId().'|'.$request->ip());
    }

    /** @return array{lat: float|null, lng: float|null} */
    private function coords(Request $request): array
    {
        $lat = $request->input('lat', $request->query('lat'));
        $lng = $request->input('lng', $request->query('lng'));

        return ['lat' => is_numeric($lat) ? (float) $lat : null, 'lng' => is_numeric($lng) ? (float) $lng : null];
    }

    /** @return array<string, mixed> */
    private function base(Store $store, BookingSettings $settings): array
    {
        return [
            'store' => $store,
            'bk' => $settings, // not `settings`: the site-wide view composer owns that name
            'tzNote' => $this->pages->zoneNote($settings),
        ];
    }

    /**
     * @param  Collection<int, BookableService>  $services
     * @return array<string, mixed>
     */
    private function timesData(Request $request, Store $store, BookingSettings $settings, Collection $services, string $location, ?Booking $own = null): array
    {
        return $this->pages->times($store, $settings, $services, $location, $this->staffId($store, $request->query('staff')), $request->query('date') !== null ? (string) $request->query('date') : null, $own)
            + ['coords' => $this->coords($request)];
    }

    /**
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private function summary(Store $store, BookingSettings $settings, \DateTimeInterface $start, \DateTimeInterface $end, array $names, ?BookingStaff $staff): array
    {
        return $this->pages->summary($settings, $start, $end, $names, $staff);
    }

    /** The cancellation policy line, computed from the real deadline (book §7, D7/D8). */
    private function policy(BookingSettings $settings, CarbonImmutable $deadline, bool $hasDeposit): string
    {
        return $this->pages->policy($settings, $deadline, $hasDeposit);
    }
}
