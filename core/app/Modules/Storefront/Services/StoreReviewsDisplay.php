<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services;

/**
 * Follow-up C — per-store gate for the storefront store-review DISPLAY (P1-13: Storefront; was under Marketplace).
 *
 * The seventeen kept themes used to compute their About-sheet / brand-row
 * review block behind the directory module's global gate (DirGate::enabled()). That gate reads the GLOBAL
 * `admin_allow_directory` Option — one value for all ~3,391 tenants — so a
 * store's own approved reviews stayed invisible unless the whole directory
 * module was switched on platform-wide.
 *
 * This decouples the DISPLAY from that global switch: it is a per-store opt-in
 * (`store_options.show_store_reviews`) that DEFAULTS OFF, so no existing tenant
 * changes behaviour on deploy. The directory module itself still uses DirGate
 * for its own public pages; only the storefront review-display gate moved here.
 *
 * The themes keep their inner `$aboutReviews->isNotEmpty()` / `$aboutRating
 * !== null` self-gate, so a store that opts in but has no approved reviews
 * still renders nothing — this flag only decides whether the compute runs.
 */
class StoreReviewsDisplay
{
    /**
     * Is the storefront store-review display opted in for this store?
     *
     * Truthy only when the per-store `show_store_reviews` option is set to a
     * truthy string. House gotcha: options arrive as the STRINGS '1'/'0', and
     * `store_options()` returns `false` for an absent key — both correctly fall
     * to `false` here (an explicit whitelist, never a naive bool cast, since a
     * non-empty '0' would be truthy under `(bool)`).
     */
    public static function enabledFor(int $storeId): bool
    {
        $value = store_options($storeId, 'show_store_reviews');

        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }
}
