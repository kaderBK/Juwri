<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Support;

use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * SEO: marketing pages whose copy exists in only SOME languages (guides, compare,
 * country pages ship e.g. `en` + `ar`, or a single `es`/`tr` block).
 *
 * Before this, every /{locale}/ prefix rendered the fallback copy and declared
 * itself canonical (route() inside the localized group builds the CURRENT-locale
 * URL), so Google indexed up to 11 duplicates per page — English text served as
 * /ur/…, Spanish as /tr/… — that split ranking signals (GSC 2026-09).
 *
 * Rule: a page is served only in the languages it is written in. Any other
 * prefix 301s to the best written language; canonical = the served language's
 * own URL; hreflang lists exactly the written languages.
 */
class ContentLocales
{
    /**
     * Locales a defs array is written in: keys whose value is a content block,
     * intersected with the platform's active languages. A flat (block-less) def
     * is single-language English.
     *
     * @param  array<array-key, mixed>  $def
     * @param  list<string>  $skip  non-locale array keys to ignore
     * @return list<string>
     */
    public static function of(array $def, array $skip = []): array
    {
        $active = array_map('strtolower', array_keys(get_active_languages()));
        $locales = [];
        foreach ($def as $key => $value) {
            if (! is_string($key) || in_array($key, $skip, true) || ! is_array($value)) {
                continue;
            }
            if (preg_match('/^[a-z]{2}$/', $key) && in_array($key, $active, true)) {
                $locales[] = $key;
            }
        }

        if ($locales === [] && in_array('en', $active, true)) {
            $locales = ['en'];
        }

        return $locales;
    }

    /**
     * The locale this request should be served in: the current one when the page
     * is written in it, else `en` when available, else the first written locale.
     *
     * @param  list<string>  $locales
     */
    public static function serveIn(array $locales, string $current): string
    {
        if ($locales === [] || in_array($current, $locales, true)) {
            return $current;
        }

        return in_array('en', $locales, true) ? 'en' : $locales[0];
    }

    /** Absolute URL of the CURRENT page in $locale (default locale = unprefixed). */
    public static function url(string $locale): string
    {
        try {
            $url = LaravelLocalization::getLocalizedURL($locale, null, [], false);
        } catch (\Throwable) {
            $url = null;
        }

        return is_string($url) && $url !== '' ? $url : url()->current();
    }

    /**
     * Self canonical for the served language — URL-prefix mode only. In session
     * mode there is one URL per page, so the view keeps its plain route() canonical.
     */
    public static function canonical(): ?string
    {
        return config('settings.include_language_code') ? self::url(get_lang()) : null;
    }

    /**
     * hreflang set for exactly the written locales, plus x-default (the default
     * locale when written, else English, else the first written locale).
     *
     * @param  list<string>  $locales
     */
    public static function hreflang(array $locales): string
    {
        if (! config('settings.include_language_code') || $locales === []) {
            return '';
        }

        $tags = [];
        foreach ($locales as $code) {
            $tags[] = '<link rel="alternate" hreflang="'.e($code).'" href="'.e(self::url($code)).'" />';
        }

        $default = strtolower((string) LaravelLocalization::getDefaultLocale());
        $x = in_array($default, $locales, true) ? $default : self::serveIn($locales, '');
        $tags[] = '<link rel="alternate" hreflang="x-default" href="'.e(self::url($x)).'" />';

        return implode("\n    ", $tags);
    }
}
