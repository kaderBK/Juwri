<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Symfony\Component\HttpFoundation\Response;

/**
 * BL-035: one canonical origin for the platform host. `www.<host>` and plain http both answered 200 on the old site, so
 * crawlers saw a duplicate of every page. With legacy_compat.seo.canonical_redirect on, a GET/HEAD for `www.<host>`,
 * or for `<host>` over http, gets a 301 to `https://<host>` + the same path and query, where <host> is the host of
 * app.url. Only that host (and its www form) is matched: store and reseller domains share this install and may have no
 * certificate. `/.well-known/` stays on http (certificate checks).
 */
final class LegacyCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! LegacyCompatRoutes::enabled() || ! config('legacy_compat.seo.canonical_redirect', false) || ! $request->isMethodSafe()) {
            return $next($request);
        }
        $platform = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $host = strtolower($request->getHost());
        if ($platform === '' || str_starts_with($platform, 'www.') || ($host !== $platform && $host !== 'www.'.$platform)) {
            return $next($request);
        }
        if ($host === $platform && $request->isSecure()) {
            return $next($request);
        }
        if (str_starts_with($request->path(), '.well-known/')) {
            return $next($request);
        }

        return redirect()->to('https://'.$platform.$request->getRequestUri(), 301);
    }
}
