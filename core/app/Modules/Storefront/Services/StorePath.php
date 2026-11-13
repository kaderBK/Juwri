<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services;

use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * P1-20: splits a storefront request path into [locale prefix, slug, store-relative rest] so the resolver
 * can move a request between address forms (`/{locale?}/{slug}/{rest}` ↔ `https://host/{locale?}/{rest}`).
 */
final class StorePath
{
    /**
     * @return array{locale: string|null, segments: list<string>}
     */
    public static function split(string $path): array
    {
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        $locale = null;
        if ($segments !== [] && config('settings.include_language_code') && in_array($segments[0], self::locales(), true)) {
            $locale = array_shift($segments);
        }

        return ['locale' => $locale, 'segments' => $segments];
    }

    /**
     * Store-relative rest of $path for the store $slug: the segments after the slug (path form), or all
     * segments when the path does not carry the slug (host form).
     *
     * @return array{locale: string|null, rest: string, had_slug: bool}
     */
    public static function rest(string $path, string $slug): array
    {
        ['locale' => $locale, 'segments' => $segments] = self::split($path);
        $hadSlug = rawurldecode($segments[0] ?? '') === $slug;
        if ($hadSlug) {
            array_shift($segments);
        } elseif (($segments[0] ?? null) === 'restaurant' && rawurldecode($segments[1] ?? '') === $slug) {
            // Old `restaurant/{slug}/{slug_title?}` URLs: the title is decoration, the page is the store root.
            $segments = [];
            $hadSlug = true;
        }

        return ['locale' => $locale, 'rest' => implode('/', $segments), 'had_slug' => $hadSlug];
    }

    /** @return list<string> */
    private static function locales(): array
    {
        try {
            return array_map('strval', LaravelLocalization::getSupportedLanguagesKeys());
        } catch (\Throwable) {
            return [];
        }
    }
}
