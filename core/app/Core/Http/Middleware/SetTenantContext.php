<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Services\Tenancy\TenantDatabaseSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-04 slice 5 (web + api groups): opens the tenant context for owner-facing routes — those guarded by an
 * owner/staff/API-token auth middleware — and closes it when the request ends. Public storefront and
 * marketplace routes, reseller portal routes and platform-admin routes never get one.
 *
 * BL-043: a route skips the context only when it carries `admin` or a middleware an ee module registered as a
 * non-tenant marker ({@see self::addNonTenantMiddleware()}: the reseller portal's owner gate and the reseller API
 * token gate). A substring match on "reseller" used to skip every owner dashboard route (they all carry the
 * `reseller.suspension` pass-through) and left them without the account/store scope.
 */
final class SetTenantContext
{
    /** Route middleware that marks an owner-facing (tenant) route. */
    private const TENANT = ['auth', 'auth:web', 'auth:api', 'auth:staff', 'auth:staff,web', 'staff.dashboard'];

    private const TENANT_CLASSES = ['ApiTokenAuth', 'StaffTokenAuth'];

    /** @var list<class-string> middleware classes of non-tenant route groups, registered by ee module providers */
    private static array $nonTenantMiddleware = [];

    /**
     * An ee module marks the middleware of a route group that is NOT an owner/staff tenant route (BL-043: the
     * reseller portal's owner gate, the reseller API token gate). Matched by exact alias or class (subclasses and
     * class aliases included) — never by a substring of the name. Call from the module provider.
     */
    public static function addNonTenantMiddleware(string $middleware): void
    {
        if (! in_array($middleware, self::$nonTenantMiddleware, true)) {
            self::$nonTenantMiddleware[] = $middleware;
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isTenantRoute($request)) {
            return $next($request);
        }
        $ctx = TenantContext::current();
        $ctx->begin($request);
        try {
            return $next($request);
        } finally {
            $ctx->end();
            TenantDatabaseSession::reset(); // slice 6: pooled/persistent sessions go back to fail-closed
        }
    }

    private function isTenantRoute(Request $request): bool
    {
        $route = $request->route();
        if ($route === null) {
            return false;
        }
        $tenant = false;
        foreach ($route->middleware() as $mw) {
            if ($mw === 'admin' || $this->isNonTenantMarker($mw)) {
                return false;
            }
            if (in_array($mw, self::TENANT, true)) {
                $tenant = true;
            }
            foreach (self::TENANT_CLASSES as $class) {
                if (str_contains($mw, $class)) {
                    $tenant = true;
                }
            }
        }

        return $tenant;
    }

    /** Test seam: forget a marker a test registered (the static list outlives one application). */
    public static function removeNonTenantMiddleware(string $middleware): void
    {
        self::$nonTenantMiddleware = array_values(array_diff(self::$nonTenantMiddleware, [$middleware]));
    }

    /** True when $mw (an alias or class name, with or without `:parameters`) is a registered non-tenant marker. */
    private function isNonTenantMarker(string $mw): bool
    {
        if (self::$nonTenantMiddleware === []) {
            return false;
        }
        $name = explode(':', $mw, 2)[0];
        if (in_array($name, self::$nonTenantMiddleware, true)) {
            return true;
        }
        $class = app('router')->getMiddleware()[$name] ?? $name;
        if (! is_string($class) || ! str_contains($class, '\\') || ! class_exists($class)) {
            return false;
        }
        foreach (self::$nonTenantMiddleware as $marker) {
            if (is_a($class, $marker, true)) {
                return true;
            }
        }

        return false;
    }
}
