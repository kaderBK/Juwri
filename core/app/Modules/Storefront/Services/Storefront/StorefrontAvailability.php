<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Storefront;

use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;

/**
 * WI-M8 — the one place that answers "may this store be served to the public?".
 *
 * The web storefront has asked this inline forever
 * (App\Http\Controllers\User\StoreController::publicView → `abort(404)`), and so does
 * the storefront payment page. The anonymous JSON menu API asked nothing, so a
 * suspended or lapsed merchant kept a fully working public menu — the endpoint the
 * customer app and the AI integrations read.
 *
 * Deliberately NARROWER than CatalogQueryService::isVisible(), which also enforces
 * `catalog_visible` (the public DIRECTORY opt-out) and excludes sandbox stores. A
 * merchant who opted out of the directory, and a developer sandbox store, must still
 * serve their own menu API. Those are different questions; do not merge them.
 *
 * Both checks are OWNER-level, not restaurant-level, so the operator surfaces can
 * render one badge per customer without loading any Post.
 */
class StorefrontAvailability
{
    /** Machine reason the owner may not be served publicly, or null when it may. */
    public static function reasonForUser(?User $user): ?string
    {
        if (! $user) {
            return 'missing';
        }

        if ((int) $user->status !== 1) {
            return 'suspended';
        }

        if (! optional($user->plan())->status) {
            return 'plan_inactive';
        }

        return null;
    }

    /** Machine reason a store may not be served publicly, or null when it may. */
    public static function reason(?Store $store): ?string
    {
        if (! $store) {
            return 'missing';
        }

        // Load the owner by FK rather than the $store->user relation: both checks are
        // owner-level and this keeps the one lookup explicit (and larastan-clean).
        // P1-23 (AUP 3.1): then the store's own sales rules — an unpublished pharmacy is not served.
        return self::reasonForUser(User::find($store->user_id))
            ?? app(SalesRestrictions::class)->unpublishedReason($store);
    }

    public static function servable(?Store $store): bool
    {
        return self::reason($store) === null;
    }

    /** Translated label for the superadmin / reseller surfaces. */
    public static function label(?string $reason): string
    {
        return match ($reason) {
            null => ___('Serving'),
            'suspended' => ___('Blocked — account suspended'),
            'plan_inactive' => ___('Blocked — plan inactive'),
            'pharmacy_market_unknown', 'pharmacy_market_disabled', 'pharmacy_licence_missing', 'pharmacy_licence_pending',
            'pharmacy_licence_rejected', 'pharmacy_licence_expired' => ___('Blocked — pharmacy not published'),
            default => ___('Blocked — no restaurant'),
        };
    }

    /**
     * Is the public-menu-API gate switched on platform-wide?
     * Superadmin → Settings → General. Absent Option means ON.
     */
    public static function apiGateEnabled(): bool
    {
        return (string) config('settings.api_public_menu_gate', '1') !== '0';
    }
}
