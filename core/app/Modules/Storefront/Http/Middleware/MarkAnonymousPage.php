<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3-03: tells the per-store service worker (sw-store.js) that this storefront page holds nothing personal, so it may
 * keep a copy for offline browsing. Laravel marks every session response `Cache-Control: private`, which the worker
 * (rightly) never stores; this adds `X-Juwri-Anonymous: 1` — a signal for the worker only, no cache semantics for
 * browsers or proxies — to a 200 HTML page of a visitor who is signed in as nobody. An order tracking page is the
 * shopper's own (the token in its URL is the key): `$ownPage` marks it for the worker too, kept as the single "last
 * viewed track page". Anything else (a signed-in customer or owner, a redirect, an error, JSON) is left alone.
 */
class MarkAnonymousPage
{
    public function handle(Request $request, Closure $next, string $ownPage = ''): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200
            || ! str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return $response;
        }
        $signedIn = auth()->check() || auth('customer')->check();
        if ($ownPage === 'own' || ! $signedIn) {
            $response->headers->set('X-Juwri-Anonymous', '1');
        }

        return $response;
    }
}
