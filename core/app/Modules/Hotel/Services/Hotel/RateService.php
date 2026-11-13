<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel;

use Illuminate\Support\Carbon;
use Juwri\Modules\Hotel\Models\RatePlan;
use Juwri\Modules\Hotel\Models\RoomRate;
use Juwri\Modules\Hotel\Models\RoomType;

/**
 * HPMS A3 — nightly rate resolution + quoting (SPEC §2).
 *
 * Resolution per (type, plan, date):
 *   1. an explicit room_rates row for (type, plan, date)      → that price, as-is
 *   2. else resolve the "parent" price for the date …
 *        - plan has a parent_id → recurse into the parent plan
 *        - plan is a root plan   → the type's base per-date price
 *          (a room_rates row for (type, NULL plan, date), else room_types.base_rate)
 *      … then apply THIS plan's modifier to it:
 *        percent → base × (1 + value/100),  fixed → base + value
 *      (value may be negative for a discounted plan; a plan with no modifier
 *       returns the parent price unchanged).
 *
 * Nights are half-open [check_in, check_out): the checkout day is never charged
 * and back-to-back stays are legal. Currency is the store currency at quote time
 * (matching the folio currency pinned at check-in) and every price is rounded to
 * that currency's minor units.
 */
class RateService
{
    /** Guard against a pathological parent chain (cycles / misconfig). */
    private const MAX_PLAN_DEPTH = 6;

    /**
     * Quote a stay. Returns the per-night breakdown, the total, and the pinned
     * currency.
     *
     * @return array{nights: array<int, array{date: string, price: float}>, total: float, currency: string}
     */
    public static function quote(RoomType $type, ?RatePlan $plan, string $checkIn, string $checkOut): array
    {
        $rid = (int) $type->store_id;
        $currency = HotelFolioService::currencyFor($rid);
        $decimals = money_decimals($currency);

        $dates = self::nightDates($checkIn, $checkOut);

        // Preload every relevant room_rates row for the type across the date span
        // and the plan chain (leaf → root), keyed [planKey][Y-m-d] = price.
        $chain = self::planChain($plan);
        $planIds = array_map(static fn (RatePlan $p) => (int) $p->id, $chain);

        $map = self::loadRateMap($type, $planIds, $checkIn, $checkOut);

        $nights = [];
        $total = 0.0;
        foreach ($dates as $date) {
            $price = round(self::priceForDate($type, $plan, $chain, $map, $date), $decimals);
            $nights[] = ['date' => $date, 'price' => $price];
            $total += $price;
        }

        return [
            'nights' => $nights,
            'total' => round($total, $decimals),
            'currency' => $currency,
        ];
    }

    /**
     * Auto-create the store's default "Standard" flexible plan on first PMS use so
     * single-rate hotels never meet the rate-plan concept. Idempotent: the name
     * "Standard" is the reserved default marker; a second call returns the same row.
     */
    public static function ensureDefaultPlan(int $storeId): RatePlan
    {
        return RatePlan::firstOrCreate(
            ['store_id' => $storeId, 'name' => 'Standard'],
            [
                'parent_id' => null,
                'modifier_type' => null,
                'modifier_value' => null,
                'refundable' => true,
                'cancel_before_hours' => 24,
                'active' => true,
            ]
        );
    }

    /**
     * The list of night dates (Y-m-d) in a half-open [in, out) span.
     *
     * @return array<int, string>
     */
    public static function nightDates(string $checkIn, string $checkOut): array
    {
        $start = Carbon::parse($checkIn)->startOfDay();
        $end = Carbon::parse($checkOut)->startOfDay();
        $dates = [];
        for ($d = $start->copy(); $d->lt($end); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }

    /**
     * Resolve the plan chain leaf → root (guarded against cycles). An empty array
     * for a null plan (base pricing only).
     *
     * @return array<int, RatePlan>
     */
    private static function planChain(?RatePlan $plan): array
    {
        $chain = [];
        $seen = [];
        $node = $plan;
        $depth = 0;
        while ($node && $depth < self::MAX_PLAN_DEPTH) {
            if (isset($seen[$node->id])) {
                break; // cycle guard
            }
            $seen[$node->id] = true;
            $chain[] = $node;
            $node = $node->parent_id ? RatePlan::find($node->parent_id) : null;
            $depth++;
        }

        return $chain;
    }

    /**
     * @param  array<int, int>  $planIds
     * @return array<string, array<string, float>> map[planKey][Y-m-d] = price; planKey = plan id string or 'base'
     */
    private static function loadRateMap(RoomType $type, array $planIds, string $checkIn, string $checkOut): array
    {
        $firstNight = Carbon::parse($checkIn)->toDateString();
        $checkoutDay = Carbon::parse($checkOut)->toDateString();

        $rows = RoomRate::where('room_type_id', $type->id)
            ->where(function ($q) use ($planIds) {
                $q->whereNull('rate_plan_id');
                if ($planIds) {
                    $q->orWhereIn('rate_plan_id', $planIds);
                }
            })
            // Half-open [firstNight, checkoutDay). Date-only bounds match rows stored
            // with or without a time suffix (see class notes on the availability SQL).
            ->where('date', '>=', $firstNight)
            ->where('date', '<', $checkoutDay)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            // Prefix plan keys so a numeric plan id never collapses to an int array
            // key (PHP casts numeric-string keys to int).
            $key = $row->rate_plan_id ? 'plan:'.(int) $row->rate_plan_id : 'base';
            $day = Carbon::parse($row->date)->toDateString();
            $map[$key][$day] = (float) $row->price;
        }

        return $map;
    }

    /**
     * @param  array<int, RatePlan>  $chain
     * @param  array<string, array<string, float>>  $map
     */
    private static function priceForDate(RoomType $type, ?RatePlan $plan, array $chain, array $map, string $date): float
    {
        // Base per-date price: NULL-plan override row, else the type's base_rate.
        $base = $map['base'][$date] ?? (float) $type->base_rate;

        if (! $plan) {
            return $base;
        }

        return self::priceForPlan($plan, $type, $base, $map, $date, 0);
    }

    /**
     * @param  array<string, array<string, float>>  $map
     */
    private static function priceForPlan(RatePlan $plan, RoomType $type, float $base, array $map, string $date, int $depth): float
    {
        // An explicit per-date override on THIS plan wins outright (no modifier).
        $override = $map['plan:'.(int) $plan->id][$date] ?? null;
        if ($override !== null) {
            return $override;
        }

        // Otherwise resolve the parent price then apply this plan's modifier.
        if ($plan->parent_id && $depth < self::MAX_PLAN_DEPTH) {
            $parent = RatePlan::find($plan->parent_id);
            $parentPrice = $parent
                ? self::priceForPlan($parent, $type, $base, $map, $date, $depth + 1)
                : $base;
        } else {
            $parentPrice = $base;
        }

        return self::applyModifier($parentPrice, $plan->modifier_type, $plan->modifier_value);
    }

    private static function applyModifier(float $base, ?string $type, ?float $value): float
    {
        if ($value === null || $type === null) {
            return $base;
        }
        if ($type === RatePlan::MODIFIER_PERCENT) {
            return $base * (1 + ($value / 100));
        }
        if ($type === RatePlan::MODIFIER_FIXED) {
            return $base + $value;
        }

        return $base;
    }
}
