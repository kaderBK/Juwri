<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Services\Laundry;

use App\Models\Order;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Laundry\Exceptions\LaundryHoldException;
use Juwri\Modules\Laundry\Models\LaundryHold;
use Juwri\Modules\Ordering\Contracts\ScheduledSlots;

/**
 * P3-11 (API-23): the laundry slot hold. ONE hold covers the pickup slot and — for a `delivery` order, where the
 * laundry collects and brings back — the delivery-back slot, for 10 minutes, with one token and one release.
 *
 * Capacity (the store's `scheduling_slot_capacity`, 0 = unlimited) is checked and the hold row written inside one
 * transaction that first locks the store's row, so two customers cannot both take the last place of a slot on
 * SQLite, MySQL or PostgreSQL. A place at a pickup slot is held by a live hold or taken by a placed order
 * (SchedulingService counts both, through LaundryHeldSlots); a place at a delivery-back slot is held by a live hold or
 * taken by a hold that became a not-cancelled order.
 *
 * The delivery-back slot must be at least the store's turnaround (`laundry_turnaround_hours`, default 24 h) after the
 * pickup, and must be a real slot (opening hours, lead time, interval) of the store's scheduling.
 */
final class LaundryHolds
{
    public const DEFAULT_TURNAROUND_HOURS = 24;

    /** At most this many live holds per holder (session or API token + IP). */
    public const MAX_LIVE = 3;

    public function __construct(private ScheduledSlots $scheduling) {}

    public function turnaroundHours(int $storeId): int
    {
        return max(0, min(720, StoreSettings::int($storeId, 'laundry_turnaround_hours', self::DEFAULT_TURNAROUND_HOURS)));
    }

    /**
     * Hold the slot(s). `$pickup` / `$return` are the slot ids the storefront shows (`Y-m-d\TH:i` in the store's
     * time). Returns the hold and its token (shown once; only the SHA-256 is stored).
     *
     * @return array{hold: LaundryHold, token: string}
     *
     * @throws LaundryHoldException
     */
    public function hold(Store $store, string $mode, string $pickup, ?string $return, ?string $holderKey = null): array
    {
        $storeId = (int) $store->id;
        if (! in_array($mode, ['pickup_slot', 'delivery'], true) || ! in_array($mode, $this->scheduling->offeredModes($store), true)) {
            throw LaundryHoldException::invalid(___('This store does not offer that fulfillment method.'));
        }
        $tz = $this->scheduling->timezone($storeId);

        try {
            $pickupAt = $this->scheduling->validate($storeId, null, $mode, $pickup);
        } catch (\InvalidArgumentException $e) {
            // a slot with no place left is "taken" (the customer picks another), not a malformed request
            $at = self::parse($pickup, $tz);
            if ($at !== null && $this->scheduling->slotIsFull($storeId, null, $at)) {
                throw LaundryHoldException::slotTaken('pickup');
            }

            throw LaundryHoldException::invalid($e->getMessage());
        }
        if ($pickupAt === null) {
            throw LaundryHoldException::invalid(___('Please pick a time slot.'));
        }
        // the order is placed up to MINUTES later and its own check applies the lead time again: hold only a slot that
        // is still bookable when the hold ends
        if ($pickupAt->lt(Carbon::now($tz)->addMinutes($this->scheduling->leadMinutes($storeId) + LaundryHold::MINUTES))) {
            throw LaundryHoldException::invalid(___('Please pick a later time.'));
        }

        $returnAt = null;
        if ($mode === 'delivery') {
            if ($return === null || trim($return) === '') {
                throw LaundryHoldException::invalid(___('Please pick a delivery time.'));
            }
            $returnAt = $this->validateReturn($storeId, $tz, $pickupAt, $return);
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($storeId, $mode, $pickupAt, $returnAt, $holderKey) {
                    // serialise holds of this store: the row lock is the capacity guard on every database
                    DB::table('stores')->where('id', $storeId)->lockForUpdate()->first();
                    $this->expireOld($storeId);

                    if ($holderKey !== null
                        && LaundryHold::query()->where('holder_key', $holderKey)->where('status', 'active')->where('expires_at', '>', now('UTC'))->count() >= self::MAX_LIVE) {
                        throw LaundryHoldException::tooMany();
                    }
                    if ($this->scheduling->slotIsFull($storeId, null, $pickupAt)) {
                        throw LaundryHoldException::slotTaken('pickup');
                    }
                    if ($returnAt !== null && $this->returnIsFull($storeId, $returnAt)) {
                        throw LaundryHoldException::slotTaken('return');
                    }

                    $token = Str::random(40);
                    $hold = LaundryHold::query()->create([
                        'store_id' => $storeId,
                        'mode' => $mode,
                        'pickup_at' => Carbon::instance($pickupAt)->utc(),
                        'return_at' => $returnAt !== null ? Carbon::instance($returnAt)->utc() : null,
                        'token_hash' => hash('sha256', $token),
                        'holder_key' => $holderKey,
                        'status' => 'active',
                        'expires_at' => now('UTC')->addMinutes(LaundryHold::MINUTES),
                    ]);

                    return ['hold' => $hold, 'token' => $token];
                });
            } catch (QueryException $e) {
                if (! self::isLockConflict($e)) {
                    throw $e;
                }
                // a deadlock / lock wait on the store row: retry, then give the time up
            }
        }

        throw LaundryHoldException::slotTaken('pickup');
    }

    /** Release a live hold (the customer went back or changed the time); both slots are free at once. */
    public function release(LaundryHold $hold): bool
    {
        return LaundryHold::query()->whereKey($hold->id)->where('status', 'active')
            ->update(['status' => 'released', 'updated_at' => now('UTC')]) === 1;
    }

    /**
     * Turn a live hold into the slots of the order being placed: marks it `used` (atomically: exactly one caller wins
     * a hold) and returns it. Call inside the order's transaction so a refused order gives the hold back.
     *
     * @throws LaundryHoldException
     */
    public function consume(Store $store, string $publicId, string $token, string $mode): LaundryHold
    {
        $hold = Str::isUuid($publicId)
            ? LaundryHold::query()->where('store_id', $store->id)->where('public_id', $publicId)->lockForUpdate()->first()
            : null;
        if (! $hold instanceof LaundryHold || ! $hold->tokenMatches($token)) {
            throw LaundryHoldException::notFound();
        }
        if (! $hold->isLive()) {
            throw LaundryHoldException::holdExpired();
        }
        if ($hold->mode !== $mode) {
            throw LaundryHoldException::invalid(___('This hold is for another way of getting your order. Please pick the times again.'));
        }
        $won = LaundryHold::query()->whereKey($hold->id)->where('status', 'active')->where('expires_at', '>', now('UTC'))
            ->update(['status' => 'used', 'updated_at' => now('UTC')]);
        if ($won !== 1) {
            throw LaundryHoldException::holdExpired();
        }

        return $hold->refresh();
    }

    /** The hold whose slots an order has (its delivery-back time), if any. */
    public function forOrder(int $orderId): ?LaundryHold
    {
        return LaundryHold::query()->where('order_id', $orderId)->where('status', 'used')->first();
    }

    /** Is the delivery-back slot at `$at` at capacity (live holds + holds of not-cancelled orders)? */
    public function returnIsFull(int $storeId, CarbonInterface $at, ?int $exceptHoldId = null): bool
    {
        $capacity = $this->scheduling->slotCapacity($storeId);
        if ($capacity <= 0) {
            return false;
        }
        $interval = $this->scheduling->slotInterval($storeId);
        $tz = $this->scheduling->timezone($storeId);
        $local = Carbon::instance($at)->setTimezone($tz);
        $start = $local->copy()->startOfDay()->addMinutes((int) floor(($local->hour * 60 + $local->minute) / $interval) * $interval);
        $key = $start->format('Y-m-d H:i');

        return ($this->returnCounts($storeId, $start, $start->copy()->addMinutes($interval), $interval, $exceptHoldId)[$key] ?? 0) >= $capacity;
    }

    /**
     * Places taken at delivery-back slots within [$start, $end], keyed `Y-m-d H:i` (store time).
     *
     * @return array<string, int>
     */
    public function returnCounts(int $storeId, CarbonInterface $start, CarbonInterface $end, int $interval, ?int $exceptHoldId = null): array
    {
        $tz = $start->timezone;
        $rows = LaundryHold::query()->where('store_id', $storeId)->whereNotNull('return_at')
            ->whereBetween('return_at', [Carbon::instance($start)->utc()->format('Y-m-d H:i:s'), Carbon::instance($end)->utc()->format('Y-m-d H:i:s')])
            ->when($exceptHoldId !== null, fn ($q) => $q->where('id', '!=', $exceptHoldId))
            ->where(function ($q) {
                $q->where(fn ($live) => $live->where('status', 'active')->where('expires_at', '>', now('UTC')))
                    ->orWhere(fn ($used) => $used->where('status', 'used')->whereIn('order_id',
                        fn ($orders) => $orders->select('id')->from((new Order)->getTable())->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'cancelled'))));
            })
            ->pluck('return_at');

        $counts = [];
        foreach ($rows as $at) {
            $key = LaundryHeldSlots::bucket($at, $tz, $interval);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The delivery-back slots of a store-time day, from the earliest allowed by the turnaround after `$pickupAt`:
     * `[at (store time), full]`. Hours, lead time and interval are the scheduling's; `full` is the delivery-back count.
     *
     * @return list<array{at: Carbon, full: bool}>
     */
    public function returnSlots(int $storeId, string $date, ?CarbonInterface $pickupAt): array
    {
        $tz = $this->scheduling->timezone($storeId);
        $slots = $this->scheduling->slotsFor($storeId, null, 'delivery', $date);
        if ($slots === []) {
            return [];
        }
        $earliest = $pickupAt !== null ? Carbon::instance($pickupAt)->setTimezone($tz)->addHours($this->turnaroundHours($storeId)) : null;
        $first = $slots[0]['at'];
        $last = end($slots)['at'];
        $capacity = $this->scheduling->slotCapacity($storeId);
        $counts = $capacity > 0 ? $this->returnCounts($storeId, $first->copy(), $last->copy()->addMinutes($this->scheduling->slotInterval($storeId)), $this->scheduling->slotInterval($storeId)) : [];

        $out = [];
        foreach ($slots as $slot) {
            /** @var Carbon $at */
            $at = $slot['at'];
            if ($earliest !== null && $at->lt($earliest)) {
                continue;
            }
            $out[] = ['at' => $at->copy(), 'full' => $capacity > 0 && ($counts[$at->format('Y-m-d H:i')] ?? 0) >= $capacity];
        }

        return $out;
    }

    /** @throws LaundryHoldException */
    private function validateReturn(int $storeId, string $tz, Carbon $pickupAt, string $requested): Carbon
    {
        try {
            $at = Carbon::parse($requested, $tz);
        } catch (\Throwable) {
            throw LaundryHoldException::invalid(___('That delivery time is invalid.'));
        }
        $earliest = $pickupAt->copy()->addHours($this->turnaroundHours($storeId));
        if ($at->lt($earliest)) {
            throw LaundryHoldException::invalid(___('Delivery is at least :hours hours after pickup. Please pick a later time.', ['hours' => (string) $this->turnaroundHours($storeId)]));
        }
        // a real delivery slot: a slot of that day (opening hours, lead time, interval), whether or not it is full
        $match = collect($this->scheduling->slotsFor($storeId, null, 'delivery', $at->format('Y-m-d')))
            ->first(fn (array $slot) => $slot['at']->format('Y-m-d H:i') === $at->format('Y-m-d H:i'));
        if ($match === null) {
            throw LaundryHoldException::invalid(___('That delivery time is not available.'));
        }

        return $at;
    }

    private static function parse(string $value, string $tz): ?Carbon
    {
        try {
            return Carbon::parse($value, $tz);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Holds past their time stop counting at once; mark them so the table tells the truth. */
    private function expireOld(int $storeId): void
    {
        LaundryHold::query()->where('store_id', $storeId)->where('status', 'active')->where('expires_at', '<=', now('UTC'))
            ->update(['status' => 'expired', 'updated_at' => now('UTC')]);
    }

    /** MySQL 1205/1213, PostgreSQL 40P01/40001/55P03: a lock conflict, not a bug. */
    public static function isLockConflict(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $code = (int) ($e->errorInfo[1] ?? 0);

        return in_array($code, [1205, 1213], true) || in_array($state, ['40001', '40P01', '55P03'], true);
    }
}
