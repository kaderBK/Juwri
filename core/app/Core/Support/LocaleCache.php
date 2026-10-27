<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cache keys for payloads that carry TRANSLATED text (labels, month names, "3 hours ago"). A key without the locale makes the
 * first request's language stick for everyone else until the TTL ends: an Arabic operator was shown the English admin
 * dashboard another operator had cached a minute earlier (found by the P1-12 Arabic journey). Build such keys with
 * {@see self::key()}; clear them with {@see self::forget()}, which covers every locale the app ships.
 */
final class LocaleCache
{
    /** `$base` with the active locale appended: `admin.dash.overview.v1` → `admin.dash.overview.v1.ar`. */
    public static function key(string $base): string
    {
        return $base.'.'.app()->getLocale();
    }

    /** Forget `$base` for every locale (the shipped language folders, the configured locales and the active one). */
    public static function forget(string $base): void
    {
        foreach (self::locales() as $locale) {
            Cache::forget($base.'.'.$locale);
        }
    }

    /** @return list<string> */
    public static function locales(): array
    {
        $dirs = array_map('basename', glob(lang_path('*'), GLOB_ONLYDIR) ?: []);

        return array_values(array_unique(array_filter(array_merge(
            $dirs,
            [app()->getLocale(), (string) config('app.locale'), (string) config('app.fallback_locale')]
        ))));
    }
}
