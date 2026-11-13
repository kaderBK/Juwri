<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Juwri\Modules\LegacyCompat\Http\Controllers\LegacyRedirectController;

/**
 * J52b (plan §5.1, LEGACYCOMPAT-SPEC §3): the old signed-in customer paths `/restaurant/{id}/…` (loyalty balance and
 * redeem, Google Wallet save-link JWTs, wallet balance and top-up, my-orders, notifications, refund request).
 *
 * Each legacy path is tied to the NAME of the core route that serves it today. Registered right after those core
 * routes, and only while the module is on:
 *
 * - while a core route still answers the exact legacy method + path (today: all of them), nothing is added, so the
 *   module never shadows a core route (security review B2);
 * - once core moves a route to its new path (J52 rename), the legacy path is added back here:
 *   - POST forms and the Google Wallet JWT GETs are SERVED DIRECT by a copy of the core route: the same controller
 *     action, the same middleware stack (web/CSRF, auth:customer, throttle and rate-limit keys, CSP) and the same
 *     where/default constraints, so auth, CSRF, rate limits and the handler's own store/tenant checks are identical
 *     (a POST is never redirected; wallet save links and pass callbacks must not redirect);
 *   - the other GETs answer a 301 to the core route's current URL with the query kept
 *     ({@see LegacyRedirectController::customerPath()}); the target applies its own auth.
 * - a core route that does not exist (renamed away, module removed) gets no legacy route at all: no route without a
 *   real handler (B2).
 *
 * Legacy customer sessions are not migrated (plan Q8): a legacy path never signs anyone in; it reaches the same
 * auth:customer guard as the new path.
 */
final class LegacyCustomerRoutes
{
    /** Serve through a copy of the core route (same action + middleware). */
    public const SERVE = 'serve';

    /** 301 to the core route's current URL. */
    public const REDIRECT = 'redirect';

    /** Action key holding the core route name a REDIRECT route points at. */
    public const TARGET_ACTION = 'legacy_compat_target';

    /** Route-name prefix of the routes added here (under {@see LegacyCompatRoutes::NAME_PREFIX}). */
    public const NAME_PREFIX = LegacyCompatRoutes::NAME_PREFIX.'customer.';

    /**
     * Legacy method + path → the core route name that serves it, and how. Parameters map to the core route's
     * parameters by position.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public const MAP = [
        ['GET', 'restaurant/{storeId}/loyalty/balance', 'publicView.loyalty.balance', self::REDIRECT],
        ['POST', 'restaurant/{storeId}/loyalty/redeem', 'publicView.loyalty.redeem', self::SERVE],
        ['GET', 'restaurant/{storeId}/google-wallet/loyalty-jwt', 'publicView.googleWallet.loyaltyJwt', self::SERVE],
        ['GET', 'restaurant/{storeId}/google-wallet/gift-card/{giftCardId}/jwt', 'publicView.googleWallet.giftCardJwt', self::SERVE],
        ['GET', 'restaurant/{storeId}/notifications', 'publicView.notifications.index', self::REDIRECT],
        ['POST', 'restaurant/{storeId}/notifications/read', 'publicView.notifications.read', self::SERVE],
        ['GET', 'restaurant/{storeId}/my-orders', 'publicView.myOrders.index', self::REDIRECT],
        ['POST', 'restaurant/{storeId}/orders/{order}/refund-request', 'publicView.myOrders.refundRequest', self::SERVE],
        ['GET', 'restaurant/{storeId}/wallet/balance', 'publicView.wallet.balance', self::REDIRECT],
        ['GET', 'restaurant/{storeId}/wallet/topup', 'publicView.wallet.topup', self::REDIRECT],
        ['POST', 'restaurant/{storeId}/wallet/topup', 'publicView.wallet.topup.start', self::SERVE],
    ];

    /** Every legacy parameter is a numeric id (store, gift card, order). */
    private const ID_PATTERN = '[0-9]+';

    /**
     * Add the legacy routes core no longer serves itself. No-op while the module is off.
     *
     * @return list<string> names of the routes added
     */
    public static function register(Router $router): array
    {
        if (! LegacyCompatRoutes::enabled()) {
            return [];
        }

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        $added = [];
        foreach (self::MAP as [$method, $uri, $coreName, $mode]) {
            $core = $routes->getByName($coreName);
            if (! $core instanceof Route || ! in_array($method, $core->methods(), true)) {
                continue; // B2: no real handler, no route
            }
            if (self::taken($routes, $method, $uri)) {
                continue; // B2: core (or anything else) still answers this exact method + path
            }
            if (count($core->parameterNames()) !== count(self::parameterNames($uri))) {
                continue; // the core route's parameters changed shape: cannot map them safely
            }

            $route = $mode === self::SERVE
                ? self::serveRoute($core, $method, $uri)
                : self::redirectRoute($uri, $coreName);
            foreach (self::parameterNames($uri) as $name) {
                $route->where($name, self::ID_PATTERN);
            }
            $route->setRouter($router)->setContainer(app());
            $routes->add($route);
            $added[] = (string) $route->getName();
        }

        $routes->refreshNameLookups();

        return $added;
    }

    /** Route name the legacy form of $coreName gets (method-qualified: two forms share the top-up path). */
    public static function routeName(string $method, string $coreName): string
    {
        return self::NAME_PREFIX.strtolower($method).'.'.$coreName;
    }

    /**
     * The absolute URL of $coreName for the legacy route's parameters (by position), with the request query kept;
     * null when the shapes differ or the target would be the request path itself (no redirect loop).
     *
     * @param  list<string>  $values
     */
    public static function targetUrl(RouteCollectionInterface $routes, string $coreName, array $values, Request $request): ?string
    {
        $core = $routes->getByName($coreName);
        if (! $core instanceof Route) {
            return null;
        }
        $names = $core->parameterNames();
        if (count($names) !== count($values)) {
            return null;
        }

        $url = route($coreName, array_combine($names, $values));
        if (rtrim((string) parse_url($url, PHP_URL_PATH), '/') === rtrim('/'.ltrim($request->path(), '/'), '/')) {
            return null;
        }

        $qs = http_build_query($request->query->all(), '', '&', PHP_QUERY_RFC3986);

        return $url.($qs !== '' ? '?'.$qs : '');
    }

    /** A copy of the core route on the legacy path: same action (controller + middleware), wheres and defaults. */
    private static function serveRoute(Route $core, string $method, string $uri): Route
    {
        $action = $core->getAction();
        unset($action['prefix'], $action[self::TARGET_ACTION]);
        $action['as'] = self::routeName($method, (string) $core->getName());

        $methods = $method === 'GET' ? ['GET', 'HEAD'] : [$method];
        $route = new Route($methods, $uri, $action);
        $route->wheres = $core->wheres;
        foreach ($core->defaults as $key => $value) {
            $route->defaults($key, $value);
        }

        return $route;
    }

    private static function redirectRoute(string $uri, string $coreName): Route
    {
        $uses = LegacyRedirectController::class.'@customerPath';

        return new Route(['GET', 'HEAD'], $uri, [
            'uses' => $uses,
            'controller' => $uses,
            'middleware' => ['web'],
            'as' => self::routeName('GET', $coreName),
            self::TARGET_ACTION => $coreName,
        ]);
    }

    private static function taken(RouteCollectionInterface $routes, string $method, string $uri): bool
    {
        foreach ($routes->get($method) as $route) {
            if ($route->uri() === $uri && $route->getDomain() === null) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function parameterNames(string $uri): array
    {
        preg_match_all('/\{(\w+)\??\}/', $uri, $m);

        return $m[1];
    }
}
