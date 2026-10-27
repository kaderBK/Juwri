<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services\Api;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Contracts\BookingApi;
use Juwri\Modules\Bookings\Data\BookingRefusal;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHold;
use Juwri\Modules\Bookings\Models\BookingStaff;
use Juwri\Modules\Bookings\Services\BookingCustomerNotices;
use Juwri\Modules\Bookings\Services\BookingPlan;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\BookingSettings;
use Juwri\Modules\Bookings\Services\BookingSetup;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Bookings\Services\ManageLink;
use Juwri\Modules\Bookings\Services\SlotEngine;

/**
 * P3-06b phase D (REVIEW, Opus: holds, deposits, tenancy) — {@see BookingApi} on the P3-10 engine. It adds no booking
 * rule of its own: availability is SlotEngine's, holds and the double-booking guard HoldService's, booking / cancel /
 * reschedule BookingService's, deposits DepositService's; this class turns public ids into rows of THIS store only,
 * encodes slots, and maps the engine's refusals to the kit error codes.
 *
 * Slot id: `bks_<staff public id without dashes | any>_<v|h><start, epoch seconds base 36>` (v = at the venue,
 * h = at home). "Any staff" slots hold with the first free eligible staff member at hold time (HoldService).
 */
final class BookingApiService implements BookingApi
{
    private const SLOT = '/^bks_([0-9a-f]{32}|any)_([vh])([0-9a-z]{1,10})$/';

    public function __construct(
        private readonly SlotEngine $engine,
        private readonly HoldService $holds,
        private readonly BookingService $bookings,
    ) {}

    public function apiAllowed(Store $store): bool
    {
        return BookingPlan::storeAllows($store, BookingPlan::BOOKING_API);
    }

    public function open(Store $store): bool
    {
        return BookingSetup::isOpen($store);
    }

    public function timezone(Store $store): string
    {
        return BookingSettings::for((int) $store->id)->timezone;
    }

    public function rules(Store $store): array
    {
        $s = BookingSettings::for((int) $store->id);

        return ['staff_choice' => $s->staffChoice, 'cancel_window_hours' => $s->cancelWindowHours, 'deposit_refunds' => $s->depositRefunds, 'at_home' => $s->atHome];
    }

    public function areServices(Store $store, array $ids): bool
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if ($ids === [] || count($ids) > 4) {
            return false;
        }
        foreach ($ids as $id) {
            if (! Str::isUuid($id)) {
                return false;
            }
        }

        return BookableService::query()->where('store_id', $store->id)->where('active', true)->whereIn('public_id', $ids)->count() === count($ids);
    }

    public function staff(Store $store, array $serviceIds): array
    {
        $settings = BookingSettings::for((int) $store->id);
        $services = $this->services($store, $serviceIds, 'venue', true);
        if (! $settings->staffChoice) {
            return [];
        }
        $publicIds = BookableService::query()->where('store_id', $store->id)->where('active', true)->pluck('public_id', 'id');

        return $this->engine->eligibleStaff((int) $store->id, $services)
            ->map(fn (BookingStaff $m) => [
                'id' => (string) $m->public_id,
                'name' => (string) $m->name,
                'role' => trim((string) $m->role),
                'photo' => trim((string) $m->photo_url),
                'service_ids' => $m->services()->pluck('booking_services.id')->map(fn ($id) => (string) ($publicIds[$id] ?? ''))->filter()->values()->all(),
            ])->values()->all();
    }

    public function days(Store $store, array $serviceIds, ?string $staffId, string $location, string $from, int $days, ?Booking $reschedule = null): array
    {
        $settings = BookingSettings::for((int) $store->id);
        [$services, $location] = $this->selection($store, $serviceIds, $location, $reschedule);
        $pool = $this->pool($store, $settings, $services, $staffId);
        $samePrice = $reschedule === null || $this->samePrice($store, $reschedule, $services);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $settings->timezone) ?: CarbonImmutable::now($settings->timezone)->startOfDay();

        $out = [];
        for ($i = 0; $i < max(1, min(31, $days)); $i++) {
            $date = $start->addDays($i)->toDateString();
            if (! $this->engine->inHorizon($settings, $date) || $pool->every(fn (BookingStaff $s) => $this->engine->windows($settings, $s, $date) === [])) {
                $out[] = ['date' => $date, 'state' => 'closed', 'first_slot_at' => null];

                continue;
            }
            $free = $samePrice ? array_values(array_filter($this->union($settings, $services, $location, $pool, $date, $reschedule), fn (array $s) => $s['free'])) : [];
            $out[] = [
                'date' => $date,
                'state' => $free === [] ? 'full' : (count($free) <= 2 ? 'few' : 'available'),
                'first_slot_at' => $free[0]['starts_at'] ?? null,
            ];
        }

        return $out;
    }

    public function slots(Store $store, array $serviceIds, ?string $staffId, string $location, string $date, ?Booking $reschedule = null): array
    {
        $settings = BookingSettings::for((int) $store->id);
        [$services, $location] = $this->selection($store, $serviceIds, $location, $reschedule);
        $pool = $this->pool($store, $settings, $services, $staffId);
        $plan = $this->engine->plan($services, $location, $settings);
        // API-8: a reschedule lists only slots at the booking's price (the same services, priced as booked)
        $rows = $reschedule === null || $this->samePrice($store, $reschedule, $services)
            ? $this->union($settings, $services, $location, $pool, $date, $reschedule)
            : [];

        return ['duration_min' => $plan['duration'], 'slots' => $rows];
    }

    public function nextFree(Store $store, array $serviceIds, ?string $staffId, string $location, CarbonImmutable $after, ?Booking $reschedule = null): ?array
    {
        try {
            $settings = BookingSettings::for((int) $store->id);
            [$services, $location] = $this->selection($store, $serviceIds, $location, $reschedule);
            $pool = $this->pool($store, $settings, $services, $staffId);
        } catch (BookingRefusal) {
            return null;
        }
        $day = $after->setTimezone($settings->timezone)->startOfDay();
        for ($i = 0; $i <= min(31, $settings->maxDays); $i++, $day = $day->addDay()) {
            $date = $day->toDateString();
            if (! $this->engine->inHorizon($settings, $date)) {
                continue;
            }
            foreach ($this->union($settings, $services, $location, $pool, $date, $reschedule) as $slot) {
                if ($slot['free'] && $slot['starts_at']->gte($after)) {
                    return $slot;
                }
            }
        }

        return null;
    }

    public function hold(Store $store, string $slotId, array $serviceIds, string $holderKey, ?Booking $reschedule = null, array $where = []): array
    {
        [$staff, $location, $start] = $this->decode($store, $slotId);
        [$services, $location] = $this->selection($store, $serviceIds, $location, $reschedule);
        if ($reschedule !== null && ! $this->samePrice($store, $reschedule, $services)) {
            throw new BookingRefusal('price_changed', ___('This time has a different price. To change the price, cancel and book again.'));
        }

        try {
            return $this->holds->hold($store, $services->pluck('id')->map(fn ($id) => (int) $id)->all(), $staff?->id, $start, $location,
                $holderKey, $where, $reschedule?->loadMissing('lines'));
        } catch (BookingException $e) {
            $next = in_array($e->errorCode, ['slot_taken', 'hold_expired'], true)
                ? $this->nextFree($store, $services->pluck('public_id')->map(fn ($id) => (string) $id)->all(), $staff?->public_id, $location, $start, $reschedule)
                : null;

            throw $this->refusal($e, 'slot_id', $next);
        }
    }

    public function findHold(Store $store, string $holdId): ?BookingHold
    {
        return Str::isUuid($holdId)
            ? BookingHold::query()->where('store_id', $store->id)->where('public_id', $holdId)->first()
            : null;
    }

    public function holdServiceIds(BookingHold $hold): array
    {
        $ids = array_map('intval', (array) $hold->service_ids);
        $map = BookableService::query()->where('store_id', $hold->store_id)->whereIn('id', $ids)->pluck('public_id', 'id');

        return array_values(array_filter(array_map(fn (int $id) => isset($map[$id]) ? (string) $map[$id] : null, $ids)));
    }

    public function releaseHold(Store $store, BookingHold $hold, string $token): ?Booking
    {
        if ((int) $hold->store_id !== (int) $store->id || ! $hold->tokenMatches($token)) {
            throw new BookingRefusal('not_found', ___('Not found.'), 404);
        }
        if ($hold->status === 'active') {
            $this->holds->release($hold);

            return null;
        }
        $booking = $hold->status === 'used' && $hold->booking_id !== null
            ? Booking::query()->where('store_id', $store->id)->whereKey($hold->booking_id)->first()
            : null;
        if (! $booking instanceof Booking || $booking->status !== 'pending_payment') {
            return null; // already used up, expired or released: nothing is bound to it any more
        }
        if (! StoreSettings::bool((int) $store->id, 'holds_release_during_payment')) {
            throw new BookingRefusal('hold_in_use', ___('This time is held for a booking that is waiting for its deposit. Finish or cancel the payment first.'));
        }
        try {
            $this->bookings->cancel($booking, 'customer');
        } catch (BookingException $e) {
            throw $this->refusal($e);
        }
        $fresh = $booking->fresh() ?? $booking;
        // API-29: the deposit-not-paid message after the customer's own release, only when the store turned it on
        app(BookingCustomerNotices::class)->pendingPaymentCancelled($fresh);

        return $fresh;
    }

    public function quote(Store $store, array $serviceIds, string $location): array
    {
        $services = $this->services($store, $serviceIds, $location === 'at_home' ? 'at_home' : 'venue');
        $currency = (string) ($services->first()->currency ?? store_currency((int) $store->id));
        $total = (int) $services->sum('price_minor');
        $deposit = min($total, (int) $services->sum(fn (BookableService $s) => $s->depositMinor()));

        return [
            'currency' => $currency,
            'lines' => $services->values()->map(fn (BookableService $s) => [
                'id' => (string) $s->public_id, 'name' => (string) $s->name, 'price_minor' => (int) $s->price_minor,
                'deposit_minor' => (int) $s->depositMinor(), 'duration_min' => (int) $s->duration_min,
            ])->all(),
            'total_minor' => $total,
            'deposit_minor' => $deposit,
            'due_later_minor' => $total - $deposit,
            'duration_min' => (int) $services->sum('duration_min'),
        ];
    }

    public function book(Store $store, string $holdId, string $holdToken, array $customer, string $clientKey, ?string $returnUrl = null): array
    {
        $replayed = Booking::query()->where('store_id', $store->id)->where('client_key', $clientKey)->exists();
        try {
            $booking = $this->bookings->book($store, $holdId, $holdToken, $customer, $clientKey);
        } catch (BookingException $e) {
            $next = null;
            if ($e->errorCode === 'hold_expired' && ($hold = $this->findHold($store, $holdId)) !== null) {
                $next = $this->nextFree($store, $this->holdServiceIds($hold), null, (string) $hold->location, CarbonImmutable::instance($hold->starts_at));
            }

            throw $this->refusal($e, 'hold_id', $next);
        }
        if (! $replayed && $returnUrl !== null && $booking->status === 'pending_payment') {
            ApiReturnUrls::remember($booking, $returnUrl);
        }

        return ['booking' => $booking, 'replayed' => $replayed];
    }

    public function byManageToken(Store $store, string $token): ?Booking
    {
        $booking = strlen($token) <= 128 ? ManageLink::resolve($store, $token) : null;
        if ($booking instanceof Booking && $booking->status === 'pending_payment'
            && $booking->payment_expires_at !== null && $booking->payment_expires_at->isPast()) {
            // the 15-minute window has ended: closed now, not at the next sweep (API-10, lazy + swept)
            $this->bookings->expireUnpaid((int) $booking->staff_id);
            $booking = $booking->fresh() ?? $booking;
        }

        return $booking;
    }

    public function manageToken(Booking $booking): string
    {
        return ManageLink::token($booking);
    }

    public function urls(Booking $booking, Store $store): array
    {
        $params = ['slug' => $store->slug, 'token' => ManageLink::token($booking)];

        return [
            'manage' => route('bookings.manage', $params),
            'calendar' => route('bookings.calendar', $params),
            'pay' => route('bookings.pay', $params),
        ];
    }

    public function serviceIds(Booking $booking): array
    {
        $booking->loadMissing('lines');
        $ids = $booking->lines->pluck('service_id')->filter()->map(fn ($id) => (int) $id)->all();
        $map = BookableService::query()->where('store_id', $booking->store_id)->whereIn('id', $ids)->pluck('public_id', 'id');

        return array_values(array_filter(array_map(fn (int $id) => isset($map[$id]) ? (string) $map[$id] : null, $ids)));
    }

    public function staffId(Booking $booking): string
    {
        return (string) BookingStaff::query()->where('store_id', $booking->store_id)->whereKey($booking->staff_id)->value('public_id');
    }

    public function cancel(Booking $booking): string
    {
        try {
            return $this->bookings->cancel($booking, 'customer');
        } catch (BookingException $e) {
            throw $this->refusal($e);
        }
    }

    public function reschedule(Store $store, Booking $booking, string $holdId, string $holdToken): Booking
    {
        if ($booking->status !== 'confirmed') {
            throw $this->refusal(BookingException::notActive());
        }
        if (! $booking->beforeDeadline()) {
            throw $this->refusal(BookingException::deadlinePassed());
        }
        $hold = $this->findHold($store, $holdId);
        if ($hold === null || (int) $hold->booking_id !== (int) $booking->id || ! $hold->tokenMatches($holdToken)) {
            throw new BookingRefusal('not_found', ___('This hold was not found.'), 404);
        }
        $services = $this->services($store, $this->holdServiceIds($hold), (string) $hold->location);
        if (! $this->samePrice($store, $booking, $services)) {
            throw new BookingRefusal('price_changed', ___('This time has a different price. To change the price, cancel and book again.'));
        }
        try {
            return $this->bookings->reschedule($booking, $holdId, $holdToken);
        } catch (BookingException $e) {
            throw $this->refusal($e, 'hold_id');
        }
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    /**
     * Active services of THIS store by public id (UUIDs only: integer ids never come in through the API).
     *
     * @param  list<string>  $ids
     * @return Collection<int, BookableService>
     */
    private function services(Store $store, array $ids, string $location, bool $anyLocation = false): Collection
    {
        foreach ($ids as $id) {
            if (! Str::isUuid($id)) {
                throw new BookingRefusal('validation_failed', ___('One of the chosen services is not available.'), 422, [], 'service_ids');
            }
        }
        if ($anyLocation) {
            // the staff list does not depend on where the visit happens
            $rows = BookableService::query()->where('store_id', $store->id)->where('active', true)->whereIn('public_id', $ids)->get();
            if ($ids === [] || count($ids) > 4 || $rows->count() !== count(array_unique($ids))) {
                throw new BookingRefusal('validation_failed', ___('One of the chosen services is not available.'), 422, [], 'service_ids');
            }

            return $rows;
        }
        try {
            return $this->engine->services((int) $store->id, $ids, $location === 'at_home' ? 'at_home' : 'venue');
        } catch (BookingException $e) {
            throw $this->refusal($e, 'service_ids');
        }
    }

    /**
     * The services and location of a listing or hold: a reschedule always uses the booking's own (API-8).
     *
     * @param  list<string>  $serviceIds
     * @return array{0: Collection<int, BookableService>, 1: string}
     */
    private function selection(Store $store, array $serviceIds, string $location, ?Booking $reschedule): array
    {
        if ($reschedule !== null) {
            if ((int) $reschedule->store_id !== (int) $store->id) {
                throw new BookingRefusal('not_found', ___('Not found.'), 404);
            }
            $own = $this->serviceIds($reschedule);
            if ($serviceIds !== [] && $serviceIds !== $own) {
                throw new BookingRefusal('validation_failed', ___('A reschedule keeps the booked services.'), 422, [], 'service_ids');
            }
            $serviceIds = $own;
            $location = (string) $reschedule->location;
        }
        $location = $location === 'at_home' ? 'at_home' : 'venue';

        return [$this->services($store, $serviceIds, $location), $location];
    }

    /**
     * @param  Collection<int, BookableService>  $services
     * @return Collection<int, BookingStaff>
     */
    private function pool(Store $store, BookingSettings $settings, Collection $services, ?string $staffId): Collection
    {
        $eligible = $this->engine->eligibleStaff((int) $store->id, $services);
        if ($staffId === null || $staffId === '' || $staffId === 'any' || ! $settings->staffChoice) {
            return $eligible;
        }
        $one = $eligible->filter(fn (BookingStaff $s) => (string) $s->public_id === $staffId)->values();
        if ($one->isEmpty()) {
            throw new BookingRefusal('validation_failed', ___('This staff member does not do the chosen services.'), 422, [], 'staff_id');
        }

        return $one;
    }

    /**
     * One row per start time across the pool: free when any staff member is free (the first free one does it).
     *
     * @param  Collection<int, BookableService>  $services
     * @param  Collection<int, BookingStaff>  $pool
     * @return list<array{id: string, staff_id: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, free: bool}>
     */
    private function union(BookingSettings $settings, Collection $services, string $location, Collection $pool, string $date, ?Booking $reschedule): array
    {
        $any = $pool->count() > 1;
        $rows = [];
        foreach ($pool as $staff) {
            foreach ($this->engine->slots($settings, $services, $location, $staff, $date, $reschedule?->id) as $slot) {
                $ts = $slot['starts_at']->getTimestamp();
                if (isset($rows[$ts]) && ($rows[$ts]['free'] || ! $slot['free'])) {
                    continue;
                }
                $rows[$ts] = [
                    'id' => $this->encode($any ? null : $staff, $location, $ts),
                    'staff_id' => (string) $staff->public_id,
                    'starts_at' => $slot['starts_at'],
                    'ends_at' => $slot['ends_at'],
                    'free' => (bool) $slot['free'],
                ];
            }
        }
        ksort($rows);

        return array_values($rows);
    }

    private function encode(?BookingStaff $staff, string $location, int $ts): string
    {
        return 'bks_'.($staff !== null ? str_replace('-', '', strtolower((string) $staff->public_id)) : 'any')
            .'_'.($location === 'at_home' ? 'h' : 'v').base_convert((string) $ts, 10, 36);
    }

    /** @return array{0: BookingStaff|null, 1: string, 2: CarbonImmutable} */
    private function decode(Store $store, string $slotId): array
    {
        if (preg_match(self::SLOT, $slotId, $m) !== 1) {
            throw new BookingRefusal('validation_failed', ___('This time is not one of the listed times.'), 422, [], 'slot_id');
        }
        $staff = null;
        if ($m[1] !== 'any') {
            $uuid = substr($m[1], 0, 8).'-'.substr($m[1], 8, 4).'-'.substr($m[1], 12, 4).'-'.substr($m[1], 16, 4).'-'.substr($m[1], 20);
            $staff = BookingStaff::query()->where('store_id', $store->id)->where('public_id', $uuid)->first();
            if (! $staff instanceof BookingStaff) {
                throw new BookingRefusal('validation_failed', ___('This time is not one of the listed times.'), 422, [], 'slot_id');
            }
        }
        $ts = (int) base_convert($m[3], 36, 10);

        return [$staff, $m[2] === 'h' ? 'at_home' : 'venue', CarbonImmutable::createFromTimestampUTC($ts)];
    }

    /**
     * API-8: the services of a reschedule still cost what the booking was booked at.
     *
     * @param  Collection<int, BookableService>  $services
     */
    private function samePrice(Store $store, Booking $booking, Collection $services): bool
    {
        return (int) $services->sum('price_minor') === (int) $booking->total_minor;
    }

    /**
     * The kit error of an engine refusal.
     *
     * @param  array<string, mixed>|null  $nextFree
     */
    private function refusal(BookingException $e, ?string $field = null, ?array $nextFree = null): BookingRefusal
    {
        $details = $nextFree !== null ? ['next_free_slot' => $nextFree] : [];

        return match ($e->errorCode) {
            'invalid_request' => new BookingRefusal('validation_failed', $e->getMessage(), 422, [], $field),
            'outside_area' => new BookingRefusal('out_of_zone', $e->getMessage(), 422),
            'too_many_holds' => new BookingRefusal('rate_limited', $e->getMessage(), 429),
            'deadline_passed' => new BookingRefusal('past_deadline', $e->getMessage(), 409),
            'payment_window_closed' => new BookingRefusal('booking_not_active', $e->getMessage(), 409),
            'hold_not_found' => new BookingRefusal('not_found', $e->getMessage(), 404),
            'slot_taken', 'hold_expired' => new BookingRefusal($e->errorCode, $e->getMessage(), 409, $details),
            default => new BookingRefusal($e->errorCode, $e->getMessage(), $e->status, $e->details),
        };
    }
}
