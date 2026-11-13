<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Support\LocalizedRoutes;

/**
 * First middleware of every group registered by {@see LocalizedRoutes::group()} in worker mode. It does what php-fpm
 * gets from `LaravelLocalization::setLocale()` running while the routes are registered: sets the request's language
 * from the URL prefix (or the default language when there is none), answers 404 for a prefix whose language was
 * switched off since the worker started, and gives the matched route its plain name back (`ar.pricing` -> `pricing`),
 * so `routeIs()`, cache keys and the like see the same names as under php-fpm.
 */
class EnsureLocalePrefix
{
    public function handle(Request $request, Closure $next): mixed
    {
        $localization = app('laravellocalization');
        $route = $request->route();
        $prefix = $route?->getAction('locale_prefix') ? strtolower((string) $route->parameter('locale')) : null;

        if ($prefix !== null) {
            if (! $localization->checkLocaleInSupportedLocales($prefix)) {
                abort(404);
            }
            $localization->setLocale($prefix);
            $route->forgetParameter('locale');
            $name = (string) $route->getAction('as');
            if (str_starts_with($name, LocalizedRoutes::NAME_PREFIX)) {
                $route->action['as'] = substr($name, strlen(LocalizedRoutes::NAME_PREFIX));
            }
        } else {
            $localization->setLocale(null);
        }

        return $next($request);
    }
}
