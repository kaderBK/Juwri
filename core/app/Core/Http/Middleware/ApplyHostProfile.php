<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Http\Hosts\HostMap;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-20 (J28) split mode, global (before the web group's StartSession): gives every origin its own session
 * cookie and trust settings. No-op in single mode.
 *
 *  - Session cookie per origin (hosts.cookies.*), host-only (no Domain attribute, so a cookie set on one
 *    host is never sent to another), `__Host-` prefixed when cookies are Secure. A cookie of one origin
 *    presented to another is simply a different, unknown name there.
 *  - Admin host: shorter session lifetime + idle timeout, 2FA enrolment forced (auth.force_2fa_admin),
 *    optional IP allowlist (hosts.admin.ip_allowlist) answered with 403 before any session starts.
 *
 * The request attribute `juwri.host_kind` carries the origin for EnforceHostSplit and BindSessionToHost.
 */
class ApplyHostProfile
{
    public const KIND_ATTRIBUTE = 'juwri.host_kind';

    public function handle(Request $request, Closure $next): Response
    {
        $kind = self::kindOf($request);
        $request->attributes->set(self::KIND_ATTRIBUTE, $kind);
        if (! HostMap::split()) {
            return $next($request);
        }

        $secure = (bool) config('session.secure') || $request->isSecure();
        $cookieKind = $kind === HostMap::STORE ? HostMap::STOREFRONT : $kind;
        $name = (string) config('hosts.cookies.'.$cookieKind, 'site_'.$cookieKind);
        config([
            'session.cookie' => ($secure ? '__Host-' : '').$name,
            'session.domain' => null,
            'session.path' => '/',
            'session.secure' => $secure,
        ]);

        // A store already built in this process (tests, Octane) keeps its old name unless told.
        app('session')->driver()->setName((string) config('session.cookie'));

        if ($kind === HostMap::ADMIN) {
            $allow = (array) config('hosts.admin.ip_allowlist', []);
            if ($allow !== [] && ! IpUtils::checkIp((string) $request->ip(), $allow)) {
                abort(403);
            }
            $minutes = max(5, (int) config('hosts.admin.session_lifetime', 30));
            config([
                'session.lifetime' => $minutes,
                'session.expire_on_close' => true,
                'auth.admin_idle_timeout' => min((int) config('auth.admin_idle_timeout', 1800), $minutes * 60),
                'auth.force_2fa_admin' => true,
            ]);
        }

        return $next($request);
    }

    public static function kindOf(Request $request): string
    {
        if ($request->attributes->has(HostMap::STORE_ATTRIBUTE)) {
            return HostMap::STORE;
        }
        $kind = HostMap::kind($request->getHost());

        return $kind === HostMap::UNKNOWN && ! HostMap::split() ? HostMap::APP : $kind;
    }
}
