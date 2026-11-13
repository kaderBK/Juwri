<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Slots;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Ordering\Models\DeliverySlotRule;

/**
 * P3-09 (kit/api `GET /delivery/slots`, basket §9): the hourly delivery / collection slots of a store with their live
 * capacity, the express option and the delivery radius.
 *
 *  - Slots: every hour inside the rule's windows (store or branch time), today + `days_ahead − 1` days, from
 *    `lead_minutes` ahead (earlier slots are not offered at all).
 *  - Capacity: at most `capacity_per_hour` orders per branch per slot, counted as live seats (holds not yet expired +
 *    orders): `remaining` = capacity − seats; `few` at 2 or fewer, `full` at 0; 0 capacity = no limit (null).
 *  - A slot id is opaque to clients ("dslot_" + order type, branch, start, signature): it is only valid for the store
 *    that listed it (the HMAC covers the store), and a decoded slot is re-checked against the rule before any hold.
 *  - Fee: a delivery slot shows the store's delivery fee (the free-delivery threshold applies in the quote); a
 *    collection slot is free.
 */
final class DeliverySlots
{
    /** Order types (core) that use these slots. */
    public const ORDER_TYPES = ['delivery' => 'd', 'pickup_slot' => 'p'];

    public const FEW = 2;

    /** The rule that applies at the branch (its own row, else the store's), active only. */
    public function rule(Store $store, ?Branch $branch = null): ?DeliverySlotRule
    {
        $rules = DeliverySlotRule::query()->where('store_id', $store->id)->where('active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branch !== null, fn ($q2) => $q2->orWhere('branch_id', $branch->id)))
            ->get();
        $own = $branch !== null ? $rules->firstWhere('branch_id', $branch->id) : null;
        $rule = $own ?? $rules->firstWhere('branch_id', null);

        return $rule instanceof DeliverySlotRule && $this->windows($rule) !== [] ? $rule : null;
    }

    /** Does the store offer time slots for this order type (a rule with windows)? */
    public function offers(Store $store, ?Branch $branch, string $orderType): bool
    {
        return isset(self::ORDER_TYPES[$orderType]) && $this->rule($store, $branch) !== null;
    }

    public function timezone(Store $store, ?Branch $branch = null): string
    {
        return (string) ($branch?->timezone ?: store_tz((int) $store->id));
    }

    /**
     * The days from `$from` (store date) with their slots.
     *
     * @return list<array{date: string, slots: list<array<string, mixed>>}>
     */
    public function days(Store $store, ?Branch $branch, string $orderType, ?string $from = null, int $days = 3): array
    {
        $rule = $this->rule($store, $branch);
        $tz = $this->timezone($store, $branch);
        $today = CarbonImmutable::now($tz)->startOfDay();
        $start = $from !== null ? CarbonImmutable::parse($from, $tz)->startOfDay() : $today;
        if ($start->lt($today)) {
            $start = $today;
        }
        $days = max(1, min(14, $days));
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->addDays($i);
            $out[] = ['date' => $date->toDateString(), 'slots' => $rule !== null && isset(self::ORDER_TYPES[$orderType])
                ? $this->slotsOn($store, $branch, $rule, $orderType, $date) : []];
        }

        return $out;
    }

    /**
     * One decoded slot of this store if it is offered and bookable now, with its live capacity; null otherwise.
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
        $rule = $this->rule($store, $branch);
        if ($rule === null) {
            return null;
        }
        $tz = $this->timezone($store, $branch);
        $startsAt = CarbonImmutable::createFromTimestampUTC($decoded['start']);
        foreach ($this->slotsOn($store, $branch, $rule, $decoded['order_type'], $startsAt->setTimezone($tz)->startOfDay()) as $slot) {
            if ($slot['id'] === $slotId) {
                return $slot + ['branch' => $branch, 'rule' => $rule];
            }
        }

        return null;
    }

    /**
     * The first slot after `$slot` (same branch and order type) with room, within the rule's horizon — the
     * `details.next_free_slot` of a 409 `slot_full`.
     *
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>|null
     */
    public function nextFree(Store $store, array $slot): ?array
    {
        /** @var Branch|null $branch */
        $branch = $slot['branch'] ?? null;
        $tz = $this->timezone($store, $branch);
        $from = CarbonImmutable::instance($slot['starts_at'])->setTimezone($tz)->toDateString();
        foreach ($this->days($store, $branch, (string) $slot['order_type'], $from, 14) as $day) {
            foreach ($day['slots'] as $candidate) {
                if ($candidate['starts_at']->gt($slot['starts_at']) && $candidate['state'] !== 'full') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Express (delivery only): the rule's fee and ETA, when the store offers it.
     *
     * @return array{fee_minor: int, eta_from: int, eta_to: int}|null
     */
    public function express(Store $store, ?Branch $branch, string $orderType): ?array
    {
        if ($orderType !== 'delivery') {
            return null;
        }
        $rule = $this->rule($store, $branch);
        if ($rule === null || $rule->express_fee_minor === null) {
            return null;
        }
        $from = (int) ($rule->express_eta_from ?? 30);
        $to = max($from, (int) ($rule->express_eta_to ?? $from + 15));

        return ['fee_minor' => (int) $rule->express_fee_minor, 'eta_from' => $from, 'eta_to' => $to];
    }

    /**
     * Is a delivery address inside the radius? null = cannot tell (no radius, no coordinates on the address or no
     * base point): the store accepts it, as a delivery without slots does today.
     *
     * @param  array<string, mixed>  $address
     */
    public function inRadius(Store $store, ?Branch $branch, array $address): ?bool
    {
        $rule = $this->rule($store, $branch);
        if ($rule === null || $rule->radius_km === null || ! isset($address['lat'], $address['lng'])
            || ! is_numeric($address['lat']) || ! is_numeric($address['lng'])) {
            return null;
        }
        $lat = $branch?->latitude !== null ? (float) $branch->latitude : ($rule->base_lat !== null ? (float) $rule->base_lat : null);
        $lng = $branch?->longitude !== null ? (float) $branch->longitude : ($rule->base_lng !== null ? (float) $rule->base_lng : null);
        if ($lat === null || $lng === null) {
            return null;
        }

        return self::km($lat, $lng, (float) $address['lat'], (float) $address['lng']) <= (float) $rule->radius_km;
    }

    /**
     * Does the store check delivery addresses against a radius (a radius and a base point: the branch's or the rule's)?
     * Then a delivery needs a pin: an order without coordinates cannot be checked and is refused, not waved through.
     */
    public function radiusApplies(Store $store, ?Branch $branch): bool
    {
        return $this->radiusCenter($store, $branch) !== null;
    }

    /** @return array{0: float, 1: float}|null the point the radius is measured from (branch, else the rule's), null = no radius */
    public function radiusCenter(Store $store, ?Branch $branch): ?array
    {
        $rule = $this->rule($store, $branch);
        if ($rule === null || $rule->radius_km === null) {
            return null;
        }
        if ($branch !== null && $branch->latitude !== null && $branch->longitude !== null) {
            return [(float) $branch->latitude, (float) $branch->longitude];
        }

        return $rule->base_lat !== null && $rule->base_lng !== null ? [(float) $rule->base_lat, (float) $rule->base_lng] : null;
    }

    /** Live seats (holds not yet expired + orders) of one slot. */
    public function taken(Store $store, int $branchKey, CarbonImmutable $slotAt): int
    {
        return DB::table('slot_seats')
            ->where('store_id', $store->id)->where('branch_key', $branchKey)
            ->where('slot_at', $slotAt->utc()->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->whereNotNull('order_id')->orWhere('expires_at', '>', now()->utc()->format('Y-m-d H:i:s')))
            ->count();
    }

    /**
     * @return array{order_type: string, branch_id: int, start: int}|null
     */
    public function decode(Store $store, string $slotId): ?array
    {
        if (preg_match('/^dslot_([dp])([0-9a-z]+)_([0-9a-z]+)_([0-9a-f]{10})$/', $slotId, $m) !== 1) {
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

        return 'dslot_'.$payload.'_'.self::signature($store, $payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function slotsOn(Store $store, ?Branch $branch, DeliverySlotRule $rule, string $orderType, CarbonImmutable $day): array
    {
        $tz = $this->timezone($store, $branch);
        $today = CarbonImmutable::now($tz)->startOfDay();
        $day = $day->setTimezone($tz)->startOfDay();
        if ($day->lt($today) || $day->gte($today->addDays(max(1, (int) $rule->days_ahead)))) {
            return [];
        }
        $earliest = CarbonImmutable::now()->addMinutes(max(0, (int) $rule->lead_minutes));
        $capacity = max(0, (int) $rule->capacity_per_hour);
        $branchKey = (int) ($branch->id ?? 0);
        $fee = $orderType === 'delivery' ? $this->deliveryFeeMinor($store, $branch) : 0;

        $slots = [];
        foreach ($this->windows($rule) as [$from, $to]) {
            $cursor = $day->setTimeFromTimeString($from);
            $end = $to === '00:00' || $to === '24:00' ? $day->addDay() : $day->setTimeFromTimeString($to);
            while ($cursor->addHour()->lte($end)) {
                $startsAt = $cursor;
                $cursor = $cursor->addHour();
                if ($startsAt->lt($earliest)) {
                    continue;
                }
                $remaining = $capacity > 0 ? max(0, $capacity - $this->taken($store, $branchKey, $startsAt)) : null;
                $state = $remaining === null ? 'available' : ($remaining === 0 ? 'full' : ($remaining <= self::FEW ? 'few' : 'available'));
                $slots[] = [
                    'id' => $this->encode($store, $orderType, $branchKey, $startsAt),
                    'order_type' => $orderType,
                    'branch_key' => $branchKey,
                    'starts_at' => $startsAt->utc(),
                    'ends_at' => $cursor->utc(),
                    'local_starts_at' => $startsAt,
                    'local_ends_at' => $cursor,
                    'capacity' => $capacity,
                    'remaining' => $remaining,
                    'state' => $state,
                    'fee_minor' => $fee,
                ];
            }
        }
        usort($slots, fn (array $a, array $b) => $a['starts_at'] <=> $b['starts_at']);

        return $slots;
    }

    /** The store's own delivery fee — P5-31: the branch's own when it has one (minor units, store currency): Order::deliveryCharge's default. */
    public function deliveryFeeMinor(Store $store, ?Branch $branch = null): int
    {
        $fee = Branch::effectiveDeliveryFee((int) $store->id, $branch);

        return Money::minorFromMajor($fee, strtoupper(store_currency((int) $store->id)));
    }

    /** @return list<array{0: string, 1: string}> valid HH:MM pairs, from < to (to may be 00:00 = midnight) */
    public function windows(DeliverySlotRule $rule): array
    {
        $out = [];
        foreach ((array) $rule->windows as $window) {
            $window = array_values((array) $window);
            $from = (string) ($window[0] ?? '');
            $to = (string) ($window[1] ?? '');
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) !== 1 || preg_match('/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/', $to) !== 1) {
                continue;
            }
            if ($to !== '00:00' && $to !== '24:00' && strcmp($to, $from) <= 0) {
                continue;
            }
            $out[] = [$from, $to];
        }

        return $out;
    }

    private static function signature(Store $store, string $payload): string
    {
        return substr(hash_hmac('sha256', 'dslot|'.$store->id.'|'.$payload, (string) config('app.key')), 0, 10);
    }

    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
