<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Shipping;

use Carbon\CarbonImmutable;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Ordering\Contracts\Shipping;
use Juwri\Modules\Ordering\Data\ShippingOption;
use Juwri\Modules\Ordering\Models\ShippingMethod;
use Juwri\Modules\Ordering\Models\ShippingZone;

/**
 * P3-08 (REVIEW, Opus: shipping money) — the shipping methods a store offers for an address and a cart.
 *
 *  - Zone: the first active zone (by position, then id) of the store that covers the address; none → the address
 *    is not shipped to (`ships_to_address` false) and only pickup-in-store methods are offered.
 *  - Fee: the method's `fee_minor`, 0 when the items subtotal (minor units, before discounts) reaches
 *    `free_over_minor`. A method priced in another currency than the store's current one is not offered (never
 *    charged in the wrong currency — DECISIONS 2026-10-01 gateway currency rule).
 *  - Dates (store time zone): the order counts from today, or from tomorrow once the method's cut-off has passed;
 *    earliest = that day + `min_days`, latest = that day + `max_days`, each moved forward to the next day the method
 *    delivers on (`delivery_days`, 0 = Sunday … 6 = Saturday; empty = every day). Pickup: the same rule.
 *
 * The same option is what the quote prices and what the order stores (fee in `delivery_fee_minor`, method and dates
 * on the order), so the shipping row of the quote equals the order's.
 */
final class ShippingQuotes implements Shipping
{
    /**
     * The store's zone for an address, or null.
     *
     * @param  array<string, mixed>  $address
     */
    public function zoneFor(Store $store, array $address): ?ShippingZone
    {
        return ShippingZone::query()
            ->where('store_id', $store->id)
            ->where('active', true)
            ->orderBy('position')->orderBy('id')
            ->get()
            ->first(fn (ShippingZone $zone) => $zone->covers($address));
    }

    /**
     * Every method offered for this address and items subtotal, in display order (zone methods by position, then
     * pickup).
     *
     * @param  array<string, mixed>  $address
     * @return list<ShippingOption>
     */
    public function options(Store $store, array $address, int $itemsSubtotalMinor, ?CarbonImmutable $now = null): array
    {
        $zone = $address !== [] ? $this->zoneFor($store, $address) : null;
        $currency = strtoupper(store_currency((int) $store->id));
        $now ??= CarbonImmutable::now(store_tz((int) $store->id));

        $methods = ShippingMethod::query()
            ->where('store_id', $store->id)
            ->where('active', true)
            ->where(function ($q) use ($zone) {
                $q->where('kind', 'pickup_in_store');
                if ($zone !== null) {
                    $q->orWhere('shipping_zone_id', $zone->id);
                }
            })
            ->orderByRaw("case when kind = 'pickup_in_store' then 1 else 0 end")
            ->orderBy('position')->orderBy('id')
            ->get();

        $out = [];
        foreach ($methods as $method) {
            $option = $this->option($store, $method, $method->kind === 'pickup_in_store' ? null : $zone, $itemsSubtotalMinor, $currency, $now);
            if ($option !== null) {
                $out[] = $option;
            }
        }

        return $out;
    }

    /**
     * One method by public id, as it is offered for this address and subtotal — null when the store does not offer it
     * there (another store's id, inactive, a zone that does not cover the address, a pickup branch that is gone).
     *
     * @param  array<string, mixed>  $address
     */
    public function find(Store $store, string $publicId, array $address, int $itemsSubtotalMinor, ?CarbonImmutable $now = null): ?ShippingOption
    {
        foreach ($this->options($store, $address, $itemsSubtotalMinor, $now) as $option) {
            if ($option->publicId() === $publicId) {
                return $option;
            }
        }

        return null;
    }

    private function option(Store $store, ShippingMethod $method, ?ShippingZone $zone, int $subtotal, string $currency, CarbonImmutable $now): ?ShippingOption
    {
        if (strtoupper((string) $method->currency_code) !== $currency) {
            return null;
        }
        if ($method->kind === 'pickup_in_store') {
            $branch = $method->pickup_branch_id !== null
                ? Branch::query()->where('store_id', $store->id)->whereKey($method->pickup_branch_id)->first()
                : null;
            if ($method->pickup_branch_id !== null && ($branch === null || ! (bool) ($branch->active ?? true))) {
                return null;
            }
        } elseif ($zone === null) {
            return null;
        }

        $free = $method->free_over_minor !== null && $subtotal >= (int) $method->free_over_minor;
        $fee = $free ? 0 : max(0, (int) $method->fee_minor);

        [$start, $cutoff] = $this->start($method, $now);
        $earliest = $this->deliveryDay($method, $start->addDays(max(0, (int) $method->min_days)));
        $latest = $this->deliveryDay($method, $start->addDays(max((int) $method->min_days, (int) $method->max_days)));
        if ($latest->lessThan($earliest)) {
            $latest = $earliest;
        }

        return new ShippingOption($method, $zone, $fee, $currency, $earliest, $latest, $cutoff, $free);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable|null} the day the order counts from, and today's cut-off */
    private function start(ShippingMethod $method, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $cutoff = null;
        if (is_string($method->cutoff_time) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $method->cutoff_time, $m) === 1) {
            $cutoff = $today->setTime((int) $m[1], (int) $m[2]);
            if ($now->greaterThanOrEqualTo($cutoff)) {
                return [$today->addDay(), $cutoff->addDay()];
            }
        }

        return [$today, $cutoff];
    }

    private function deliveryDay(ShippingMethod $method, CarbonImmutable $day): CarbonImmutable
    {
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($method->delivery_days ?? [])), fn (int $d) => $d >= 0 && $d <= 6)));
        if ($days === []) {
            return $day;
        }
        for ($i = 0; $i < 7 && ! in_array($day->dayOfWeek, $days, true); $i++) {
            $day = $day->addDay();
        }

        return $day;
    }

    /** Minor units of a major amount in the store currency (helper for callers that hold major amounts). */
    public static function minor(Store $store, float $major): int
    {
        return Money::minorFromMajor($major, strtoupper(store_currency((int) $store->id)));
    }
}
