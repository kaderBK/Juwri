<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

/**
 * F7-11 (feature-platform gap fix): mask plan-gated per-store toggles on the
 * STOREFRONT read path, centrally.
 *
 * The problem, found during FIX-2's F2-4: ~20 theme `index.blade.php` files
 * read `$postOptions->gift_cards_enabled` / `curbside_enabled` /
 * `allow_order_scheduling` DIRECTLY. Those three toggles are owner-settable
 * booleans, and their SKUs are plan_gated — but the entitlement only ever got
 * consulted when the diner tried to ACT (GiftCardService::enabled(),
 * SchedulingService::enabled(), the curbside branch in StoreController@order).
 * So an unentitled store still rendered the gift-card tile, the curbside
 * checkbox and the "schedule for later" picker, and the diner discovered the
 * refusal only after committing. This is not a new drift class — the kill
 * switch behaved the same way — but it is a bad storefront.
 *
 * The fix is deliberately ONE place rather than ~20 blades: mask the toggles
 * on the object the storefront renders from, so every theme (current, future,
 * and the Liquid/marketplace themes that wrap the same object in OptionsDrop)
 * is fixed at once and no future theme can reintroduce the bug.
 *
 * ── WHERE THE LINE IS DRAWN ────────────────────────────────────────────────
 * This masks ONLY the storefront render paths:
 *   • StoreController@publicView   (the storefront itself, and the ThemeContext
 *                                  it builds for Liquid themes)
 *   • PaymentController@...       (the storefront checkout/return views)
 *   • CustomerAuthController      (the storefront customer account view)
 *
 * It deliberately does NOT mask:
 *   • the OWNER's settings form (`user/posts/edit.blade.php`, fed by
 *     StoreController@edit) — the merchant must still see and be able to keep
 *     their own toggle, otherwise lapsing a SKU would silently ERASE a setting
 *     they configured and re-entitling would come back "off";
 *   • the save path (`StoreController`'s toggle whitelist) — same reason;
 *   • `store_options()` itself — masking there would hit both of the above, plus
 *     the grandfather command, exports and backups, which must read truth.
 *
 * Subject is the STORE OWNER, via FeatureAccess::storeAllows(): a storefront
 * visitor is anonymous, so `dashboard_owner()` is null there and an owner-
 * scoped check is the only correct one. (Attaching `feature:` middleware to a
 * public route 403s every visitor — dead end recorded in FIX-1/FIX-2.)
 */
class StorefrontToggleMask
{
    /**
     * StoreOption name => feature key. Only plan-gated toggles belong here; an
     * ungated key is a no-op anyway (FeatureAccess short-circuits to the kill
     * switch), so listing one is harmless but pointless.
     */
    public const GATED_TOGGLES = [
        'allow_order_scheduling' => 'scheduled_orders',
        'curbside_enabled' => 'curbside',
        'gift_cards_enabled' => 'gift_cards',
    ];

    /**
     * Blank every gated toggle the store's owner is not entitled to (or that
     * the platform kill switch has turned off).
     *
     * Mutates and returns the SAME object: `store_options()` builds a fresh
     * stdClass per call (json_decode(json_encode(...))), so nothing is shared
     * and nothing leaks between requests.
     *
     * @param  mixed  $options  the object store_options($storeId) returned
     * @return mixed the same object, with refused toggles set false
     */
    public static function apply($options, ?int $storeId)
    {
        if (! is_object($options) || ! $storeId) {
            return $options;
        }

        foreach (self::GATED_TOGGLES as $option => $featureKey) {
            // Already off / never set → nothing to hide, and no resolver call.
            if (empty($options->{$option})) {
                continue;
            }

            if (! FeatureAccess::storeAllows($storeId, $featureKey)) {
                $options->{$option} = false;
            }
        }

        return $options;
    }
}
