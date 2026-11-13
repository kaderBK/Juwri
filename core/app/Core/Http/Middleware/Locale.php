<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the app language for routes WITHOUT a language prefix
 * (`app.locale` alias: the studio app shell, JSON endpoints, the
 * storefront hubs). Prefixed routes get theirs from the URL via mcamara,
 * whose LocaleSessionRedirect also keeps session('locale') in step with the
 * last prefixed page, so this works in both locale modes.
 *
 * Order of precedence:
 *  1. The storefront hubs (fusion, order-hub) use their own memory,
 *     session('mk_locale'). An unprefixed page elsewhere means "default
 *     locale", so the app-wide value must never leak into the storefront.
 *  2. The app-wide session('locale').
 *  3. A signed-in user's saved preferred_locale, except on the storefront
 *     surfaces (fusion, order-hub, the public store page) which only switch
 *     by session or URL.
 */
class Locale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        if ($locale !== null) {
            App::setLocale($locale);
        }

        return $next($request);
    }

    private function resolve(Request $request): ?string
    {
        $storefrontHub = $request->is('fusion', 'fusion/*', 'order-hub', 'order-hub/*');

        if ($storefrontHub && session()->has('mk_locale')) {
            return session('mk_locale');
        }

        if (session()->has('locale')) {
            return session('locale');
        }

        $preferred = Auth::user()?->preferred_locale;

        if (! empty($preferred) && ! $storefrontHub && ! $request->routeIs('publicView', '*.publicView')) {
            return $preferred;
        }

        return null;
    }
}
