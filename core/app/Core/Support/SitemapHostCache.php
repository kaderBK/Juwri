<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cache keys of the sitemap sections. Every <loc> is built from the request host, so a section cached by one host
 * (a reseller domain, a mail host) must never be served to another: the first host to warm a shared key used to fill
 * the platform sitemap with its own domain for the whole TTL.
 *
 * The platform host (the host of app.url) keeps the plain key, so the existing invalidations still hit it. Any other
 * host gets its own key, versioned by a generation number: bumping the generation drops every other host's copy at
 * once ({@see forgetVariants()}).
 */
final class SitemapHostCache
{
    private const GENERATION_KEY = 'sitemap.host_gen';

    public static function key(string $key): string
    {
        $host = strtolower(request()->getHost());
        $platform = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host === '' || $host === $platform) {
            return $key;
        }

        return $key.':'.$host.':g'.self::generation();
    }

    public static function generation(): int
    {
        return (int) Cache::get(self::GENERATION_KEY, 0);
    }

    /** Drops every non-platform host's cached sitemap sections at once. */
    public static function forgetVariants(): void
    {
        Cache::forever(self::GENERATION_KEY, self::generation() + 1);
    }

    /** The restaurant sections changed: the platform host's copies and every other host's. */
    public static function forgetRestaurants(): void
    {
        $pages = max(1, (int) Cache::get('sitemap.restaurants.pages', 1));
        Cache::forget('sitemap.restaurants');
        Cache::forget('sitemap.restaurants.pages');
        for ($n = 1; $n <= $pages; $n++) {
            Cache::forget('sitemap.restaurants.'.$n);
        }
        self::forgetVariants();
    }
}
