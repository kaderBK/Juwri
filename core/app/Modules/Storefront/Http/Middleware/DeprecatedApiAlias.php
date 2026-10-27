<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3-06b (kit/api README §5, API-4, API-12): the legacy storefront endpoints stay, frozen, for 12 months after
 * launch. Every answer of an alias — success or error — carries `Deprecation: true`, `Sunset: <HTTP-date>`
 * (config api.storefront.legacy_sunset) and `Link: </developers>; rel="deprecation", <successor>; rel="successor-version"`,
 * the header pattern the deprecated query-string token already used.
 *
 *   ->middleware(DeprecatedApiAlias::class.':/api/v1/storefront/catalog')
 *
 * A `{name}` in the successor is filled from the route parameter of that name (e.g. `{token}` on the track alias).
 */
class DeprecatedApiAlias
{
    public function handle(Request $request, Closure $next, string $successor = ''): Response
    {
        $response = $next($request);

        return self::decorate($response, self::successorFor($request, $successor));
    }

    public static function decorate(Response $response, string $successor): Response
    {
        // Relative references (RFC 8288 §3), as the kit examples send them.
        $link = '</developers>; rel="deprecation"';
        if ($successor !== '') {
            $link .= ', <'.$successor.'>; rel="successor-version"';
        }
        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Sunset', (string) config('api.storefront.legacy_sunset', 'Sun, 03 Oct 2027 00:00:00 GMT'));
        $response->headers->set('Link', $link);

        return $response;
    }

    private static function successorFor(Request $request, string $successor): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($request) {
            $value = $request->route($m[1]);

            return is_scalar($value) ? rawurlencode((string) $value) : '';
        }, $successor);
    }
}
