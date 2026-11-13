<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

use Illuminate\Routing\Router;

/**
 * J52b: registers the legacy route file, but only when legacy_compat.enabled is true. With the switch off this adds
 * no route at all, so a fresh install's route table is byte-identical to one without the module.
 *
 * Called near the top of routes/web.php so legacy paths win over the newer feature paths and the {slug} catch-all
 * (LEGACYCOMPAT-SPEC §3 precedence); a legacy route whose shape overlaps core paths is marked fallback() so core's
 * own routes still win (see Routes/legacy.php). Per-slug conflict routes (plan §5.4): {@see registerSlugConflicts()}.
 * OPEN: the /{locale}/{slug} forms are not wired yet (spec §3, plan §5.1).
 */
final class LegacyCompatRoutes
{
    /** Route-name prefix of every legacy route (tests use it to prove none exists when off). */
    public const NAME_PREFIX = 'legacy_compat.';

    /** Sample legacy request paths (GET) for the off-state test; kept here so old words stay inside this module. */
    public const PROBE_PATHS = [
        '/restaurant/sample-store/sample-title',
        '/restaurant/12345/google-wallet/loyalty-jwt',
        '/restaurant/12345/google-wallet/gift-card/7/jwt',
        '/storage/restaurant/logo/logo-1.png',
    ];

    /**
     * Core GET paths with the same shape as a legacy route (security review B2): with the module ON each must still
     * resolve to the same core route as with the module off. Test data, kept here so old words stay in this module.
     */
    public const CORE_SHARED_PATHS = [
        '/restaurant/12345/my-orders',
        '/restaurant/12345/notifications',
        '/restaurant/12345/loyalty',
        '/restaurant/12345/floor-map',
        '/restaurant/12345/waitlist',
        '/restaurant/12345/loyalty/balance',
        '/restaurant/12345/wallet/balance',
        '/restaurant/12345/wallet/topup',
        '/restaurant/12345/google-wallet/loyalty-jwt',
        '/restaurant/12345/google-wallet/gift-card/7/jwt',
        '/restaurant/sample-store/sample-title',
        '/restaurant/sample-store',
    ];

    /** Name of the core storefront order route (test data for the charge-to-room HTTP flow). */
    public const CORE_ORDER_ROUTE = 'store.submitOrder';

    /** Route parameter of {@see CORE_ORDER_ROUTE}. */
    public const CORE_ORDER_ROUTE_PARAM = 'store';

    public static function enabled(): bool
    {
        return (bool) config('legacy_compat.enabled', false);
    }

    /**
     * The old signed-in customer paths ({@see LegacyCustomerRoutes}). Called from routes/web.php right after the core
     * customer routes, so it can see which legacy paths core still answers itself.
     *
     * @return list<string> names of the routes added
     */
    public static function registerCustomer(): array
    {
        if (! self::enabled()) {
            return [];
        }

        return LegacyCustomerRoutes::register(app(Router::class));
    }

    /**
     * Explicit store-page routes for imported slugs that newer paths shadow ({@see LegacySlugConflicts}), bound to
     * the old platform's hosts. Called from routes/web.php right after {@see register()}, BEFORE every core route, with
     * the store page's action and middleware. Adds nothing while the module is off or no conflict is stored.
     *
     * @param  list<string>  $middleware
     * @return list<string> names of the routes added
     */
    public static function registerSlugConflicts(string $action, array $middleware): array
    {
        if (! self::enabled()) {
            return [];
        }
        $router = app(Router::class);

        return LegacySlugConflicts::registerRoutes(static function (string $host, string $pattern, string $name) use ($router, $action, $middleware): void {
            // The domain must be set BEFORE the route is added: the collection keys routes by domain + uri, so a
            // domain set afterwards would leave this route under the bare `{slug}` key, where the core page replaces it.
            $router->group(['domain' => $host], static function () use ($router, $action, $pattern, $middleware, $name): void {
                $router->get('{slug}', $action)->where('slug', $pattern)->middleware($middleware)->name($name);
            });
        });
    }

    public static function register(): void
    {
        if (! self::enabled()) {
            return;
        }

        (static function (): void {
            require __DIR__.'/../Routes/legacy.php';
        })();
    }
}
