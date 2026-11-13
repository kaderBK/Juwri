<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * rev/seo R14: replaces mcamara's LocaleSessionRedirect in the prefix-mode
 * route groups. That middleware 302-redirects any URL to the session locale's
 * variant, which makes the DEFAULT locale unreachable once a session picked a
 * prefixed one (hideDefaultLocaleInURL: "/" has no locale param, so the
 * session wins and bounces "/" -> "/en" forever — the Arabic landing could
 * never be reached again, and crawler-visible 302s are SEO poison anyway).
 *
 * This only REMEMBERS: it writes the locale mcamara resolved from the URL
 * into the session, so unprefixed endpoints behind app.locale (studio
 * shell, JSON APIs) keep serving the visitor's last-seen language. It never
 * redirects.
 */
class RememberLocale
{
    public function handle(Request $request, Closure $next)
    {
        /*
         * Resolve an explicit locale prefix at request time. Route definitions
         * call LaravelLocalization::setLocale() while routes are registered,
         * but that registration-time side effect is absent when Laravel loads
         * cached routes. It can also be overwritten by later application boot
         * work. In either case `/en/store-slug` reached the correct storefront
         * route while still rendering `lang="ar" dir="rtl"`.
         *
         * The prefix is trusted only when it belongs to the runtime-supported
         * locale registry. An ordinary unprefixed slug therefore keeps the
         * configured default locale.
         */
        $prefix = strtolower((string) $request->segment(1));
        $localization = app('laravellocalization');

        if ($prefix !== '' && $localization->checkLocaleInSupportedLocales($prefix)) {
            $localization->setLocale($prefix);
        }

        session(['locale' => app()->getLocale()]);

        return $next($request);
    }
}
