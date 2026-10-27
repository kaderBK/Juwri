<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg;

use App\Models\Reservation;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\Branch;
use Juwri\Modules\Reservations\Services\ReservationBookingService;

/**
 * GOOGLE-PARTNER Lane C — Reserve-with-Google reservation availability.
 *
 * ONE source of truth for bookable reservation slots + per-slot capacity, shared
 * by the storefront booking form (publicBook capacity guard), the Google booking
 * server (BatchAvailabilityLookup / CreateBooking, C5) and the Availability feed
 * (C4). Copies the proven SchedulingService shape (windowFor / lockSlot / store-tz /
 * overnight bleed) but counts COVERS (sum of party_size), not order count.
 *
 * Capacity model: `reservation_covers_per_slot` caps how many diners a store seats
 * in one interval bucket. **Default 0 = UNLIMITED — this default is LOAD-BEARING:
 * every existing reservation (and its test) predates capacity, so 0 keeps the guard
 * a no-op. A non-zero default would break the Reservation* suite.** A slot admits a
 * new party of size P iff `booked_covers(bucket) + P <= capacity` (unlimited always
 * admits). Booked covers sum party_size of the active statuses
 * [pending, confirmed, seated] in the bucket.
 *
 * Capacity is STORE-WIDE: `reservation_covers_per_slot` is a per-store option, so
 * covers are counted across all branches (the optional $branch argument only narrows the
 * timezone + opening window — it never partitions the covers pool). Per-branch capacity
 * is a documented follow-up. The Google feed (C4) publishes one merchant per branch
 * ("Google merchant = branch", owner 2026-10-12) while availability stays store-wide
 * across those branches.
 *
 * All settings are per-restaurant StoreOption keys (see keys()), all defaulting so a
 * store that never configured reservations behaves exactly as before.
 */
class ReservationAvailabilityService
{
    /** Default slot interval in minutes. */
    public const DEFAULT_INTERVAL = 30;

    /** Default per-slot covers. 0 = unlimited (LOAD-BEARING — see class docblock). */
    public const DEFAULT_COVERS = 0;

    /** Default maximum party size advertised/accepted. */
    public const DEFAULT_MAX_PARTY = 8;

    /** Default horizon in days a diner may book ahead. */
    public const DEFAULT_HORIZON_DAYS = 30;

    /** Active reservation statuses that consume a slot's covers. */
    public const ACTIVE_STATUSES = ['pending', 'confirmed', 'seated'];

    /**
     * Owner-settable StoreOption keys this service reads (persisted in C7).
     *
     * @return array<int,string>
     */
    public static function keys(): array
    {
        return [
            'reservation_slot_interval',
            'reservation_covers_per_slot',
            'reservation_max_party',
            'reservation_horizon_days',
        ];
    }

    public function slotInterval(int $storeId): int
    {
        $v = (int) store_options($storeId, 'reservation_slot_interval', self::DEFAULT_INTERVAL);

        return $v > 0 ? $v : self::DEFAULT_INTERVAL;
    }

    /** Per-slot covers cap; 0 = unlimited. */
    public function coversPerSlot(int $storeId): int
    {
        return max(0, (int) store_options($storeId, 'reservation_covers_per_slot', self::DEFAULT_COVERS));
    }

    public function maxParty(int $storeId): int
    {
        $v = (int) store_options($storeId, 'reservation_max_party', self::DEFAULT_MAX_PARTY);

        return $v > 0 ? $v : self::DEFAULT_MAX_PARTY;
    }

    public function horizonDays(int $storeId): int
    {
        $v = (int) store_options($storeId, 'reservation_horizon_days', self::DEFAULT_HORIZON_DAYS);

        return $v > 0 ? $v : self::DEFAULT_HORIZON_DAYS;
    }

    /** Timezone: branch wins, then the store `timezone` option, then app default. */
    public function timezone(int $storeId, ?Branch $branch = null): string
    {
        if ($branch && ! empty($branch->timezone)) {
            return $branch->timezone;
        }

        return (string) (store_options($storeId, 'timezone') ?: config('app.timezone', 'UTC'));
    }

    /**
     * Does the branch publish hours? A branch-less store OR an empty-hours branch has
     * NO real window (windowFor would fall back to always-open). The Google feed must
     * NOT advertise 24/7 for such stores — C4 checks this and emits nothing; the
     * storefront human path keeps the always-open fallback in slotsFor().
     */
    public function branchHasHours(?Branch $branch): bool
    {
        return $branch instanceof Branch && ! empty($branch->hours);
    }

    /**
     * Bookable slots for a local date (Y-m-d). Ordered list of
     * ['at'=>Carbon(tz), 'label'=>'H:i', 'spots_total'=>int, 'spots_open'=>int, 'full'=>bool].
     * Respects branch hours (incl. overnight + closed days), horizon, blackouts and
     * per-slot covers. spots_total 0 means unlimited (never full). [] when out of range
     * or the day is closed/blacked-out.
     *
     * @return array<int,array<string,mixed>>
     */
    public function slotsFor(int $storeId, ?Branch $branch, string $date): array
    {
        $tz = $this->timezone($storeId, $branch);
        try {
            $day = Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
        } catch (\Throwable $e) {
            return [];
        }
        $now = Carbon::now($tz);

        // Past-day + horizon guard (day-granular).
        if ($day->lt($now->copy()->startOfDay())
            || $day->gt($now->copy()->addDays($this->horizonDays($storeId))->startOfDay())) {
            return [];
        }

        [$open, $close, $closed] = $this->windowFor($branch, $day);
        if ($closed) {
            return [];
        }

        $interval = $this->slotInterval($storeId);
        $capacity = $this->coversPerSlot($storeId);

        $start = $day->copy()->setTimeFromTimeString($open);
        $end = $day->copy()->setTimeFromTimeString($close);
        if ($end->lte($start)) {
            $end->addDay(); // overnight window rolls to next day
        }

        $counts = $capacity > 0 ? $this->coverCounts($storeId, $start, $end, $interval) : [];

        $slots = [];
        for ($t = $start->copy(); $t->lt($end); $t->addMinutes($interval)) {
            if ($t->lt($now)) {
                continue; // no past slots today
            }
            // Skip a slot that starts inside a blackout.
            if (ReservationBookingService::blackoutHit($storeId, $t->format('Y-m-d H:i'))) {
                continue;
            }
            $used = (int) ($counts[$t->format('Y-m-d H:i')] ?? 0);
            $open_ = $capacity > 0 ? max(0, $capacity - $used) : 0;
            $slots[] = [
                'at' => $t->copy(),
                'label' => $t->format('H:i'),
                'spots_total' => $capacity,               // 0 = unlimited
                'spots_open' => $capacity > 0 ? $open_ : 0,
                'full' => $capacity > 0 && $open_ <= 0,
            ];
        }

        return $slots;
    }

    /**
     * Is the slot containing $at already at/over covers capacity for a party of
     * $partySize? Read-only, atomic re-check helper (mirrors SchedulingService).
     * Unlimited capacity (0) is never full. Buckets in store tz, floored to interval.
     */
    public function slotIsFull(int $storeId, ?Branch $branch, Carbon $at, int $partySize = 1, ?int $ignoreReservationId = null): bool
    {
        $capacity = $this->coversPerSlot($storeId);
        if ($capacity <= 0) {
            return false;
        }
        $slotStart = $this->bucketStart($storeId, $branch, $at);
        $interval = $this->slotInterval($storeId);

        $counts = $this->coverCounts(
            $storeId,
            $slotStart,
            $slotStart->copy()->addMinutes($interval),
            $interval,
            $ignoreReservationId
        );
        $used = (int) ($counts[$slotStart->format('Y-m-d H:i')] ?? 0);

        return ($used + max(1, $partySize)) > $capacity;
    }

    /**
     * Capacity gate for a create: is there room for this party at this time?
     * The inverse of slotIsFull; the name the callers read.
     */
    public function hasCapacity(int $storeId, ?Branch $branch, Carbon $at, int $partySize, ?int $ignoreReservationId = null): bool
    {
        return ! $this->slotIsFull($storeId, $branch, $at, $partySize, $ignoreReservationId);
    }

    /**
     * G2: is $at a bookable slot for a party of $partySize — in the future, inside
     * the horizon, within the store's max party, on a non-closed day inside the
     * opening window, and not blacked out? The create/reschedule gate; capacity is
     * still re-checked under the per-slot lock separately. Built from the same
     * windowFor()/horizon logic slotsFor() uses, so it never disagrees with the
     * advertised feed. A branch-less store keeps the always-open fallback.
     */
    public function slotIsBookable(int $storeId, Carbon $at, int $partySize, ?Branch $branch = null): bool
    {
        if ($partySize < 1 || $partySize > $this->maxParty($storeId)) {
            return false;
        }

        $tz = $this->timezone($storeId, $branch);
        $local = $at->copy()->setTimezone($tz);
        $now = Carbon::now($tz);

        // Must be in the future, and within the booking horizon (day-granular as slotsFor()).
        if ($local->lte($now)) {
            return false;
        }
        if ($local->copy()->startOfDay()->gt($now->copy()->addDays($this->horizonDays($storeId))->startOfDay())) {
            return false;
        }

        [$open, $close, $closed] = $this->windowFor($branch, $local);
        if ($closed) {
            return false;
        }
        $start = $local->copy()->startOfDay()->setTimeFromTimeString($open);
        $end = $local->copy()->startOfDay()->setTimeFromTimeString($close);
        if ($end->lte($start)) {
            $end->addDay(); // overnight window rolls to the next day
        }
        if ($local->lt($start) || $local->gte($end)) {
            return false;
        }

        return ! ReservationBookingService::blackoutHit($storeId, $local->format('Y-m-d H:i'));
    }

    /**
     * Acquire the per-slot capacity lock for a booking and re-check occupancy while
     * held. The caller MUST hold the returned lock across the reservation INSERT + its
     * COMMIT then release it (see ReservationController::publicBook and CreateBooking).
     * Returns null when no lock is needed — unlimited capacity — so the caller can
     * `optional($lock)->release()` unconditionally.
     *
     * Throws \InvalidArgumentException when the slot is momentarily busy (lock timeout)
     * or already full for this party — callers render it as the "pick another time"
     * message (storefront) or a SLOT_UNAVAILABLE booking_failure (Google).
     */
    public function lockSlot(int $storeId, ?Branch $branch, Carbon $at, int $partySize, ?int $ignoreReservationId = null): ?Lock
    {
        if ($this->coversPerSlot($storeId) <= 0) {
            return null; // unlimited → nothing to serialise
        }

        $slotStart = $this->bucketStart($storeId, $branch, $at);
        // Store-wide covers ⇒ store-wide lock key (branch-agnostic): two bookers in the
        // same slot at different branches still serialise against the shared pool.
        $lock = Cache::lock(
            'rwg-res-slot:'.$storeId.':'.$slotStart->format('YmdHi'),
            15
        );
        try {
            $lock->block(5);
        } catch (LockTimeoutException $e) {
            throw new \InvalidArgumentException(__('That time slot is busy. Please try again.'));
        }
        if ($this->slotIsFull($storeId, $branch, $at, $partySize, $ignoreReservationId)) {
            $lock->release();
            throw new \InvalidArgumentException(__('That time slot is full. Please pick another.'));
        }

        return $lock;
    }

    // --- internals -------------------------------------------------------

    /**
     * Opening window [open,close,closed] for a branch on a day; store-wide fallback = always open.
     *
     * @return array{0:string,1:string,2:bool}
     */
    private function windowFor(?Branch $branch, CarbonInterface $day): array
    {
        if (! $branch || empty($branch->hours)) {
            return ['00:00', '23:59', false];
        }
        $key = Branch::DAYS[$day->dayOfWeekIso - 1];
        $h = $branch->dayHours($key);

        return [$h['open'], $h['close'], (bool) $h['closed']];
    }

    /** Start of the interval bucket that contains $at, in the store tz. */
    private function bucketStart(int $storeId, ?Branch $branch, Carbon $at): Carbon
    {
        $tz = $this->timezone($storeId, $branch);
        $interval = $this->slotInterval($storeId);
        $local = $at->copy()->setTimezone($tz);
        $mins = (int) floor(($local->hour * 60 + $local->minute) / $interval) * $interval;

        return $local->copy()->startOfDay()->addMinutes($mins);
    }

    /**
     * Sum booked covers (party_size of active reservations) per interval bucket within
     * [start,end), STORE-WIDE (branch-agnostic — capacity is a store-level option).
     * reserved_at is a naive wall-clock in the store tz, so we bucket in the same tz we
     * generate slots in — never shifting the wall-clock.
     *
     * @return array<string,int> 'Y-m-d H:i' bucket => covers
     */
    private function coverCounts(int $storeId, CarbonInterface $start, CarbonInterface $end, int $interval, ?int $ignoreReservationId = null): array
    {
        $tz = $start->timezone;
        $q = Reservation::query()
            ->where('store_id', $storeId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($ignoreReservationId !== null, fn ($query) => $query->where('id', '!=', $ignoreReservationId))
            ->whereBetween('reserved_at', [
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            ]);

        $counts = [];
        // ->get()->map, never pluck across two columns — and party_size runs through
        // its integer cast this way.
        foreach ($q->get(['reserved_at', 'party_size']) as $row) {
            // reserved_at is cast to a Carbon datetime on the model (NOT NULL column).
            $local = Carbon::parse($row->reserved_at->format('Y-m-d H:i:s'), $tz);
            $mins = (int) floor(($local->hour * 60 + $local->minute) / $interval) * $interval;
            $slot = $local->copy()->startOfDay()->addMinutes($mins);
            $key = $slot->format('Y-m-d H:i');
            $counts[$key] = ($counts[$key] ?? 0) + max(1, (int) $row->party_size);
        }

        return $counts;
    }
}
