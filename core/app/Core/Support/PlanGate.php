<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use App\Services\Entitlements;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;

/**
 * Pricing follow-ups (owner 2026-10-05, PRICING.md §3): the ONE server-side gate for the on/off plan keys the
 * pricing build added — delivery, coupons, WhatsApp order alerts, multi-branch reports and the Odoo connector —
 * and the upsell copy every gate shows.
 *
 * A plan that holds no value for a key keeps the feature ({@see Entitlements::allowsUnlessWithheld()}): plans made
 * before the keys existed keep what they had. Community grants every key (CommunityEntitlementSource), so a
 * self-hosted Community install is never gated here. A store without an owner keeps today's behaviour (allowed).
 */
final class PlanGate
{
    public const DELIVERY = 'delivery';

    public const COUPONS = 'coupons';

    public const WHATSAPP_ORDER_ALERTS = 'whatsapp_order_alerts';

    public const BRANCH_REPORTS = 'branch_reports';

    public const ODOO_CONNECTOR = 'odoo_connector';

    public const KEYS = [self::DELIVERY, self::COUPONS, self::WHATSAPP_ORDER_ALERTS, self::BRANCH_REPORTS, self::ODOO_CONNECTOR];

    /** May this owner use the feature? */
    public static function allows(?User $owner, string $key): bool
    {
        if (! $owner instanceof User) {
            return true;
        }

        try {
            return Entitlements::allowsUnlessWithheld($owner, $key);
        } catch (\Throwable $e) {
            report($e);

            return true; // a resolver failure never takes a feature away mid-service (same as "no value")
        }
    }

    /** May this store (its owner's plan) use the feature? */
    public static function storeAllows(Store|int|null $store, string $key): bool
    {
        if (is_int($store)) {
            $store = Store::query()->find($store);
        }
        if (! $store instanceof Store) {
            return true;
        }

        return self::allows($store->user, $key);
    }

    /** Owner-facing upsell copy for a gate (translated). */
    public static function upsell(string $key): string
    {
        return match ($key) {
            self::DELIVERY => ___('Delivery is not included in your current plan. Upgrade your plan to take delivery orders with your own delivery charges.'),
            self::COUPONS => ___('Coupons are not included in your current plan. Upgrade your plan to create discount codes for your customers.'),
            self::WHATSAPP_ORDER_ALERTS => ___('WhatsApp order alerts are not included in your current plan. Upgrade your plan to send order confirmations and status updates to your customers on WhatsApp.'),
            self::BRANCH_REPORTS => ___('Comparing every branch side by side is not included in your current plan. Upgrade your plan to see all your branches in one report.'),
            self::ODOO_CONNECTOR => ___('The Odoo connector is not included in your current plan. Upgrade your plan to send your orders, payments and stock moves to Odoo.'),
            default => ___('This feature is not included in your current plan.'),
        };
    }

    /** Customer-facing refusal (never mentions the store's plan). */
    public static function customerMessage(string $key): string
    {
        return match ($key) {
            self::DELIVERY => ___('This store does not offer delivery right now. Please choose another order type.'),
            self::COUPONS => ___('Coupons cannot be used at this store.'),
            default => ___('This is not available at this store.'),
        };
    }

    /** Where the upsell sends the owner (the plans page), or null when the route is absent (Community). */
    public static function upgradeUrl(): ?string
    {
        return Route::has('subscription') ? route('subscription') : null;
    }
}
