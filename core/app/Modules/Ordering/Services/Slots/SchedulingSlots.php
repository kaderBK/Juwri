<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Slots;

use Carbon\CarbonImmutable;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;

/**
 * Lane C (P3-06b, kit/api README §7 food; API-11) — food schedule-ahead slots as a second slot source next to the
 * grocery rule slots ({@see DeliverySlots}): the times are SchedulingService's (the owner's pre-order settings: interval,
 * lead time, days ahead, branch hours, `scheduling_slot_capacity` orders per slot), listed by `GET /delivery/slots` for
 * a food store's takeaway (the kit's `pickup`) or delivery, and held for 10 minutes by `POST /holds` like any slot.
 *
 *  - Capacity = SchedulingService::slotUsage: scheduled orders that are not cancelled + the seats of live holds no order
 *    took yet. `remaining` = capacity − usage; `few` at 2 or fewer, `full` at 0; capacity 0 = no limit (null).
 *  - Ids are opaque ("pslot_" + order type, branch, start, signature): only valid for the store that listed them (the
 *    HMAC covers the store) and re-checked against the live schedule before any hold.
 *  - Optional: a food order may still be "as soon as possible" — unlike a rule slot, the store does not require one.
 *  - A hold takes a seat in `slot_seats` under the same per-slot lock as the order paths (SchedulingService::lockKey),
 *    so web/POS scheduled orders and API holds never overfill a slot ({@see SlotHolds}).
 */
final class SchedulingSlots
{
    /** Core order types listed as schedule-ahead slots (`t` takeaway = the kit's food `pickup`, `d` delivery). */
    public const ORDER_TYPES = ['takeaway' => 't', 'delivery' => 'd'];

    public const FEW = DeliverySlots::FEW;

    public function __construct(private readonly SchedulingService $scheduling, private readonly DeliverySlots $delivery) {}

    /** Does the store take scheduled orders of this type (its pre-order settings, plan and switch)? */
    public function offers(Store $store, ?Branch $branch, string $orderType): bool
    {
        return isset(self::ORDER_TYPES[$orderType]) && $this->scheduling->enabled((int) $store->id, $orderType);
    }

    public function timezone(Store $store, ?Branch $branch = null): string
    {
        return $this->scheduling->timezone((int) $store->id, $branch);
    }

    public static function isOwn(string $slotId): bool
    {
        return str_starts_with($slotId, 'pslot_');
    }

    /** @return list<array{date: string, slots: list<array<string, mixed>>}> */
    public function days(Store $store, ?Branch $branch, string $orderType, ?string $from = null, int $days = 3): array
    {
        $tz = $this->timezone($store, $branch);
        $today = CarbonImmutable::now($tz)->startOfDay();
        $start = $from !== null ? CarbonImmutable::parse($from, $tz)->startOfDay() : $today;
        if ($start->lt($today)) {
            $start = $today;
        }
        $out = [];
        for ($i = 0, $n = max(1, min(14, $days)); $i < $n; $i++) {
            $date = $start->addDays($i)->toDateString();
            $out[] = ['date' => $date, 'slots' => $this->offers($store, $branch, $orderType) ? $this->slotsOn($store, $branch, $orderType, $date) : []];
        }

        return $out;
    }

    /**
     * One slot of this store when it is offered and bookable now, with its live capacity; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function find(Store $store, ?Branch $contextBranch, string $slotId): ?array
    {
        $decoded = $this->decode($store, $slotId);
        if ($decoded === null) {
            return null;
        }
        $branch = $decoded['branch_id'] > 0
            ? Branch::query()->where('store_id', $store->id)->whereKey($decoded['branch_id'])->first()
            : null;
        if ($decoded['branch_id'] > 0 && $branch === null) {
            return null;
        }
        if ($contextBranch !== null && (int) $contextBranch->id !== (int) ($branch->id ?? 0)) {
            return null; // a slot of another branch than the one the client is ordering from
        }
        if (! $this->offers($store, $branch, $decoded['order_type']) || $this->delivery->offers($store, $branch, $decoded['order_type'])) {
            return null; // not taking pre-orders of this type, or the store lists its delivery-slot rule's slots instead
        }
        $local = CarbonImmutable::createFromTimestampUTC($decoded['start'])->setTimezone($this->timezone($store, $branch));
        // an overnight window lists its after-midnight slots under the day it opened
        foreach ([$local->toDateString(), $local->subDay()->toDateString()] as $date) {
            foreach ($this->slotsOn($store, $branch, $decoded['order_type'], $date) as $slot) {
                if ($slot['id'] === $slotId) {
                    return $slot + ['branch' => $branch];
                }
            }
        }

        return null;
    }

    /**
     * The first slot after `$slot` (same branch and order type) with room, within the store's horizon.
     *
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>|null
     */
    public function nextFree(Store $store, array $slot): ?array
    {
        /** @var Branch|null $branch */
        $branch = $slot['branch'] ?? null;
        $from = CarbonImmutable::instance($slot['starts_at'])->setTimezone($this->timezone($store, $branch))->toDateString();
        $horizon = min(14, $this->scheduling->maxDays((int) $store->id) + 1);
        foreach ($this->days($store, $branch, (string) $slot['order_type'], $from, $horizon) as $day) {
            foreach ($day['slots'] as $candidate) {
                if ($candidate['starts_at']->gt($slot['starts_at']) && $candidate['state'] !== 'full') {
                    return $candidate + ['branch' => $branch];
                }
            }
        }

        return null;
    }

    /**
     * Occupancy of one slot now (scheduled orders + live hold seats no order took): the hold step's capacity check,
     * read inside its transaction.
     *
     * @param  array<string, mixed>  $slot
     */
    public function usage(Store $store, array $slot): int
    {
        /** @var Branch|null $branch */
        $branch = $slot['branch'] ?? null;
        $start = CarbonImmutable::instance($slot['local_starts_at']);
        $counts = $this->scheduling->slotUsage((int) $store->id, $branch, $start, CarbonImmutable::instance($slot['local_ends_at'])->subSecond());

        return (int) ($counts[$start->format('Y-m-d H:i')] ?? 0);
    }

    /**
     * The order-path lock of the slot (SchedulingService::lockKey), shared with the scheduled order writers.
     *
     * @param  array<string, mixed>  $slot
     */
    public function lockKey(Store $store, array $slot): string
    {
        /** @var Branch|null $branch */
        $branch = $slot['branch'] ?? null;

        return SchedulingService::lockKey((int) $store->id, $branch, CarbonImmutable::instance($slot['local_starts_at']));
    }

    /**
     * @return array{order_type: string, branch_id: int, start: int}|null
     */
    public function decode(Store $store, string $slotId): ?array
    {
        if (preg_match('/^pslot_([td])([0-9a-z]+)_([0-9a-z]+)_([0-9a-f]{10})$/', $slotId, $m) !== 1) {
            return null;
        }
        $payload = $m[1].$m[2].'_'.$m[3];
        if (! hash_equals(self::signature($store, $payload), $m[4])) {
            return null;
        }
        $type = array_search($m[1], self::ORDER_TYPES, true);

        return $type === false ? null : ['order_type' => (string) $type, 'branch_id' => (int) base_convert($m[2], 36, 10), 'start' => (int) base_convert($m[3], 36, 10) * 60];
    }

    public function encode(Store $store, string $orderType, int $branchKey, CarbonImmutable $startsAt): string
    {
        $payload = self::ORDER_TYPES[$orderType].base_convert((string) $branchKey, 10, 36).'_'.base_convert((string) intdiv($startsAt->getTimestamp(), 60), 10, 36);

        return 'pslot_'.$payload.'_'.self::signature($store, $payload);
    }

    /** @return list<array<string, mixed>> */
    private function slotsOn(Store $store, ?Branch $branch, string $orderType, string $date): array
    {
        $storeId = (int) $store->id;
        $raw = $this->scheduling->slotsFor($storeId, $branch, $orderType, $date);
        if ($raw === []) {
            return [];
        }
        $interval = $this->scheduling->slotInterval($storeId);
        $capacity = $this->scheduling->slotCapacity($storeId);
        $first = CarbonImmutable::instance($raw[0]['at']);
        $last = CarbonImmutable::instance($raw[count($raw) - 1]['at']);
        $usage = $capacity > 0 ? $this->scheduling->slotUsage($storeId, $branch, $first, $last->addMinutes($interval)->subSecond()) : [];
        $branchKey = (int) ($branch->id ?? 0);
        $fee = $orderType === 'delivery' ? $this->delivery->deliveryFeeMinor($store, $branch) : 0;

        $slots = [];
        foreach ($raw as $entry) {
            $start = CarbonImmutable::instance($entry['at']);
            $end = $start->addMinutes($interval);
            $remaining = $capacity > 0 ? max(0, $capacity - (int) ($usage[$start->format('Y-m-d H:i')] ?? 0)) : null;
            $slots[] = [
                'id' => $this->encode($store, $orderType, $branchKey, $start),
                'order_type' => $orderType,
                'branch_key' => $branchKey,
                'starts_at' => $start->utc(),
                'ends_at' => $end->utc(),
                'local_starts_at' => $start,
                'local_ends_at' => $end,
                'capacity' => $capacity,
                'remaining' => $remaining,
                'state' => $remaining === null ? 'available' : ($remaining === 0 ? 'full' : ($remaining <= self::FEW ? 'few' : 'available')),
                'fee_minor' => $fee,
                'source' => 'scheduling',
            ];
        }

        return $slots;
    }

    private static function signature(Store $store, string $payload): string
    {
        return substr(hash_hmac('sha256', 'pslot|'.$store->id.'|'.$payload, (string) config('app.key')), 0, 10);
    }
}
