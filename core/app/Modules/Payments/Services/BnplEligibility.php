<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Models\PaymentGateway;

/**
 * P0 — BNPL (Buy-Now-Pay-Later) eligibility layer.
 *
 * Tabby / Tamara appear at checkout ONLY when:
 *   - the owner has enabled BNPL (post_option `restaurant_bnpl_enabled`), AND
 *   - the order total is at or above the owner-set minimum basket
 *     (post_option `restaurant_bnpl_min_total`, default 200, currency-agnostic
 *     number — the provider enforces the real currency floor), AND
 *   - the specific BNPL gateway is installed for the restaurant
 *     (post_option `restaurant_{gateway}_install`).
 *
 * On PLAN / wallet / membership checkout there is NO basket threshold — BNPL is
 * available whenever the platform-level gateway install is on.
 *
 * This service is the single source of truth; the checkout gateway list and the
 * G4/G5 drivers both consult it, so the rule never drifts between call sites.
 */
class BnplEligibility
{
    /** Default minimum basket when the owner has not set one. */
    public const DEFAULT_MIN_TOTAL = 200.0;

    /** BNPL gateway folders this layer governs. */
    public const GATEWAYS = ['tabby', 'tamara'];

    /**
     * Order-context eligibility for one BNPL gateway.
     *
     * @param  Store|int  $store  restaurant Store (or its id)
     * @param  float  $total  order grand total
     * @param  string  $gateway  'tabby' | 'tamara'
     */
    public static function availableForOrder($store, float $total, string $gateway): bool
    {
        $storeId = $store instanceof Store ? $store->id : (int) $store;

        if (! in_array($gateway, self::GATEWAYS, true)) {
            return false;
        }

        // Gateway must be installed for this restaurant.
        if (! self::truthy(store_options($storeId, 'restaurant_'.$gateway.'_install'))) {
            return false;
        }

        // Owner master toggle for BNPL.
        if (! self::truthy(store_options($storeId, 'restaurant_bnpl_enabled'))) {
            return false;
        }

        // Minimum basket floor.
        $min = self::minTotal($storeId);

        return $total >= $min;
    }

    /**
     * P1-23 part 5: a store payment of the store's own customer (gift card, store-credit top-up, reservation /
     * catering deposit — no basket): the gateway installed for the store AND the owner's BNPL master toggle on. Like
     * the plan context there is no basket floor (it is an order rule).
     *
     * @param  Store|int  $store
     */
    public static function availableForStorePayment($store, string $gateway): bool
    {
        $storeId = $store instanceof Store ? $store->id : (int) $store;

        return in_array($gateway, self::GATEWAYS, true)
            && self::truthy(store_options($storeId, 'restaurant_'.$gateway.'_install'))
            && self::truthy(store_options($storeId, 'restaurant_bnpl_enabled'));
    }

    /**
     * Plan / wallet / membership eligibility — platform install only, no
     * basket threshold. The gateway's platform payment_gateways row + admin
     * enable are the switch here (mirrors the other gateways).
     */
    public static function availableForPlan(string $gateway): bool
    {
        if (! in_array($gateway, self::GATEWAYS, true)) {
            return false;
        }

        return (bool) PaymentGateway::where('payment_folder', $gateway)
            ->where('payment_install', true)
            ->exists();
    }

    /** Owner-configured minimum basket, or the default. */
    public static function minTotal($storeId): float
    {
        $raw = store_options($storeId, 'restaurant_bnpl_min_total');
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return self::DEFAULT_MIN_TOTAL;
        }

        return (float) $raw;
    }

    /** Accept 1 / '1' / 'Yes' / true as enabled; everything else off. */
    private static function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $v = strtolower(trim((string) $value));

        return in_array($v, ['1', 'yes', 'true', 'on'], true);
    }
}
