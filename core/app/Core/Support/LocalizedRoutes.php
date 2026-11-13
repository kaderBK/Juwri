<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Closure;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Middleware\EnsureLocalePrefix;
use Juwri\Core\Models\Language;
use Juwri\Core\Routing\LocalizedRouteLookup;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * Registers a route group that is reachable with an optional language prefix (`/pricing`, `/ar/pricing`).
 *
 * php-fpm (default, unchanged): the group is registered once with the prefix the CURRENT request carries
 * (`LaravelLocalization::setLocale()` reads the URL's first segment while the routes are being registered).
 *
 * Octane worker / `route:cache` (JUWRI_LOCALE_ROUTES): the routes are registered once for the whole life of the process,
 * so they cannot depend on one request. The group is registered for EVERY active language instead (prefixed, route names
 * prefixed with `<code>.`) plus once unprefixed. {@see EnsureLocalePrefix} sets the locale
 * and gives the matched route its plain name back; {@see LocalizedRouteLookup} makes `route('x')`
 * answer the current request's language.
 *
 * JUWRI_LOCALE_ROUTES: unset/`auto` = on inside an Octane worker, otherwise off; `all` = every active language; or a
 * comma list (`ar,fr`) naming the languages (tests).
 */
final class LocalizedRoutes
{
    /** Name prefix of the single prefixed copy (`loc.pricing`); EnsureLocalePrefix gives the plain name back. */
    public const NAME_PREFIX = 'loc.';

    /** @var list<string>|null */
    private static ?array $registered = null;

    /**
     * The language codes the worker registered prefixed groups for (memoised for the life of the process).
     *
     * @return list<string>
     */
    public static function registered(): array
    {
        return self::$registered ??= self::codes();
    }

    /** For tests: forget the memo. */
    public static function forget(): void
    {
        self::$registered = null;
    }

    /**
     * The language codes to register prefixed groups for; [] = the per-request (php-fpm) behaviour.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $setting = (string) config('laravellocalization.localeRoutes', 'auto');

        if ($setting === 'auto') {
            $setting = isset($_SERVER['LARAVEL_OCTANE']) ? 'all' : 'off';
        }

        if ($setting === 'off' || $setting === '') {
            return [];
        }

        if ($setting !== 'all') {
            return array_values(array_filter(array_map('trim', explode(',', $setting))));
        }

        // EVERY shipped language (the lang/ directories), active or not, so a language switched on after the worker booted
        // is reachable. No database query here (route registration must stay cheap). EnsureLocalePrefix refuses (404) a
        // prefix that is not active for THIS request, so an inactive language stays unreachable.
        $codes = array_map('basename', glob(lang_path('*'), GLOB_ONLYDIR) ?: []);
        $codes = array_filter(array_map('strval', $codes), fn (string $c) => preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $c) === 1 && $c !== 'vendor');

        return array_values(array_unique($codes));
    }

    /** @param  array<string, mixed>  $attributes */
    public static function group(array $attributes, Closure $routes): void
    {
        // A group that is not language-prefixed (session-language mode, include_language_code off) is a plain group.
        if (! ($attributes['localized'] ?? false)) {
            Route::group($attributes, $routes);

            return;
        }
        unset($attributes['localized']);

        $codes = self::registered();

        if ($codes === []) {
            Route::group($attributes + ['prefix' => LaravelLocalization::setLocale()], $routes);

            return;
        }

        $middleware = (array) ($attributes['middleware'] ?? []);

        // ONE prefixed group, `{locale}` constrained to the supported codes (route count does not grow with languages),
        // before the unprefixed one, as in php-fpm where an active language prefix wins over a store slug of the same name.
        Route::group(array_merge($attributes, [
            'prefix' => '{locale}',
            'as' => self::NAME_PREFIX,
            'locale_prefix' => true,
            'where' => ['locale' => implode('|', array_map('preg_quote', $codes))],
            'middleware' => array_merge(['locale.prefix'], $middleware),
        ]), $routes);

        Route::group(array_merge($attributes, ['middleware' => array_merge(['locale.prefix'], $middleware)]), $routes);
    }
}
