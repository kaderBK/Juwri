<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Middleware\EnsureStaffDashboardPermission;

/**
 * Staff sign in through StaffLoginController, which logs the OWNER into the web
 * guard (the dashboard is owner-scoped) and marks the session with
 * staff_session_id. EnsureStaffDashboardPermission (`staff.dashboard`) then
 * limits what that session may do — but only on routes that carry it. Routes
 * guarded by plain `auth` (account security, social connect, two-factor, the
 * reseller and partner portals, …) were reachable by any staff member AS the
 * owner: link their own Google login to the owner account, reset the owner's
 * 2FA, read recovery codes.
 *
 * This fence applies the same default-deny staff map to EVERY route that
 * requires a web login, whenever the session is a staff session. Public pages
 * (a staff member browsing a storefront) and routes for other guards (the
 * staff portal, `auth:staff`) are unaffected.
 */
class StaffSessionFence
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! is_staff_dashboard() || ! $this->requiresWebLogin($request)) {
            return $next($request);
        }

        return app(EnsureStaffDashboardPermission::class)->handle($request, $next);
    }

    private function requiresWebLogin(Request $request): bool
    {
        $route = $request->route();
        if (! $route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if ($middleware === 'staff.dashboard') {
                return false; // the route already enforces the map itself
            }
            if ($middleware === 'auth' || $middleware === 'auth:web' || str_starts_with($middleware, 'auth:web,')) {
                return true;
            }
        }

        return false;
    }
}
