<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Juwri\Core\Http\Hosts\HostMap;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-20 (J28) split mode, web group (after StartSession): what each origin may serve. No-op in single mode.
 *
 *  - The session is bound to the origin that created it: a session id replayed under another origin's cookie
 *    (the session store is shared) is invalidated before anything reads it.
 *  - admin host: superadmin only. Anonymous visitors reach only the auth flow and the admin routes (which
 *    send them to login); a signed-in non-admin is signed out. Store pages are never served here.
 *  - app host: owner/staff dashboard. Admin routes are 404 and a superadmin session is signed out and sent
 *    to the admin host (`app.` refuses superadmin logins — checked after the login request too).
 *  - storefront and store hosts, marketing host: never dashboard, admin, reseller or login routes (404; the
 *    marketing host sends GET login/register/dashboard links to the app host).
 */
class EnforceHostSplit
{
    /** Middleware that marks a dashboard (web-guard) route. `auth:customer` / `guest:customer` are not. */
    private const DASHBOARD_MIDDLEWARE = [
        'auth', 'auth:web', 'auth:staff', 'guest', 'guest:web', 'admin', 'admin.session', 'staff.dashboard',
        'csp.dashboard', 'dashboard.sync', 'password.confirm', 'checkRegistrationDisabled',
        AdminMiddleware::class,
        EnsureStaffDashboardPermission::class,
    ];

    /** @var list<string> dashboard-marking middleware of ee modules (P1-13 13f: the Reseller portal's owner gate) */
    private static array $moduleDashboardMiddleware = [];

    /** An ee module marks its dashboard routes' middleware as dashboard (web-guard) routes. Call from its provider. */
    public static function addDashboardMiddleware(string $middleware): void
    {
        if (! in_array($middleware, self::$moduleDashboardMiddleware, true)) {
            self::$moduleDashboardMiddleware[] = $middleware;
        }
    }

    /** Route names / URIs of the web-guard sign-in flow an anonymous visitor may reach on the admin host. */
    private const AUTH_FLOW = ['login', 'logout', 'password.', '2fa.', 'verification.', 'passkey.login.'];

    private const AUTH_FLOW_URIS = ['login', 'logout', 'two-factor/challenge', 'password/email', 'password/reset', 'passkeys/login', 'passkeys/login/options'];

    public const ORIGIN_KEY = '_juwri_origin';

    public function handle(Request $request, Closure $next): Response
    {
        if (! HostMap::split()) {
            // Single mode: only a module-registered extra storefront host (a migrated install's old platform host)
            // is restricted — store pages yes, dashboard / admin / login no, no web-guard session there.
            if (! HostMap::isStorefrontAlias($request->getHost())) {
                return $next($request);
            }
            $route = $request->route();
            if ($route instanceof Route && ($denied = $this->deny($request, $route, HostMap::STOREFRONT)) !== null) {
                return $denied;
            }
            $response = $next($request);

            return $this->wrongUser($request, HostMap::STOREFRONT) ?? $response;
        }
        $kind = (string) ($request->attributes->get(ApplyHostProfile::KIND_ATTRIBUTE) ?: ApplyHostProfile::kindOf($request));
        $this->bindSession($request, $kind);

        $route = $request->route();
        if ($route instanceof Route && ($denied = $this->deny($request, $route, $kind)) !== null) {
            return $denied;
        }

        $response = $next($request);

        // A login that just succeeded on the wrong origin is undone before its redirect leaves.
        return $this->wrongUser($request, $kind) ?? $response;
    }

    private function bindSession(Request $request, string $kind): void
    {
        if (! $request->hasSession()) {
            return;
        }
        $session = $request->session();
        $origin = $kind.'|'.strtolower($request->getHost());
        $bound = $session->get(self::ORIGIN_KEY);
        if ($bound !== null && $bound !== $origin) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();
        }
        $session->put(self::ORIGIN_KEY, $origin);
    }

    private function deny(Request $request, Route $route, string $kind): ?Response
    {
        $name = (string) $route->getName();
        // Dashboard, admin, reseller-portal and web-guard sign-in routes (login/register/password/2FA).
        $dashboard = self::isDashboardRoute($route) || self::isAuthFlow($route) || $name === 'register'
            || str_starts_with($name, 'admin.') || str_starts_with($name, 'reseller.') || str_starts_with($name, 'staff.');
        $storePage = self::isStorePage($route);

        switch ($kind) {
            case HostMap::ADMIN:
                if ($storePage) {
                    abort(404);
                }
                if ($this->wrongUser($request, $kind) !== null) {
                    return $this->wrongUser($request, $kind);
                }
                if (! Auth::guard('web')->check() && ! str_starts_with($name, 'admin.') && ! self::isAuthFlow($route)) {
                    abort(404);
                }

                return null;

            case HostMap::APP:
                if (str_starts_with($name, 'admin.')) {
                    abort(404);
                }

                return $this->wrongUser($request, $kind);

            case HostMap::MARKETING:
                if ($dashboard) {
                    if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                        $path = ltrim($request->getPathInfo(), '/');
                        $query = $request->getQueryString();

                        return new RedirectResponse(HostMap::appUrl().'/'.$path.($query ? '?'.$query : ''), 302);
                    }
                    abort(404);
                }

                return null;

            default: // storefront host, store hosts, unknown
                if ($dashboard) {
                    abort(404);
                }

                return null;
        }
    }

    /** Sign out a web-guard user who does not belong on this origin. */
    private function wrongUser(Request $request, string $kind): ?Response
    {
        $user = Auth::guard('web')->user();
        if (! $user) {
            return null;
        }
        $isAdmin = (bool) $user->isAdmin();
        $wrong = match ($kind) {
            HostMap::ADMIN => ! $isAdmin,
            HostMap::APP => $isAdmin,
            default => true, // no dashboard session anywhere else
        };
        if (! $wrong) {
            return null;
        }
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        $target = ($kind === HostMap::APP ? HostMap::adminUrl() : HostMap::appUrl()).'/login';
        $message = $kind === HostMap::APP ? 'Superadmin accounts sign in on the admin site.' : 'Sign in on the dashboard site.';

        return $request->expectsJson()
            ? response()->json(['success' => false, 'message' => $message, 'redirect' => $target], 403)
            : new RedirectResponse($target, 302);
    }

    public static function isDashboardRoute(Route $route): bool
    {
        $middleware = $route->gatherMiddleware();
        foreach ($middleware as $m) {
            if (is_string($m) && (in_array($m, self::DASHBOARD_MIDDLEWARE, true) || in_array($m, self::$moduleDashboardMiddleware, true))) {
                return true;
            }
        }

        return false;
    }

    /** A public store page: first segment (after a locale prefix) is `{slug}`, or the old `restaurant/{…}` URL. */
    public static function isStorePage(Route $route): bool
    {
        $parts = explode('/', ltrim($route->uri(), '/'));
        $parts[] = '';
        if (count($parts) > 2 && preg_match('/^[a-z]{2}(?:[-_][A-Za-z]{2,4})?$/', $parts[0])) {
            array_shift($parts);
        }

        return $parts[0] === '{slug}' || ($parts[0] === 'restaurant' && str_starts_with($parts[1] ?? '', '{'));
    }

    private static function isAuthFlow(Route $route): bool
    {
        if (in_array(ltrim($route->uri(), '/'), self::AUTH_FLOW_URIS, true)) {
            return true;
        }
        $name = (string) $route->getName();
        foreach (self::AUTH_FLOW as $prefix) {
            if ($name === $prefix || (str_ends_with($prefix, '.') && str_starts_with($name, $prefix))) {
                return true;
            }
        }

        return false;
    }
}
