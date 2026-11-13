<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Support\SitemapHostCache;

/**
 * Daily housekeeping: refresh the sitemaps.
 *
 * The sitemap is a cached dynamic route (/sitemap.xml → marketing, blog,
 * restaurants sections, plus one Fusion sitemap per tenant: 0 = platform, then
 * each reseller). Dropping the caches makes the next request rebuild them. A
 * static sitemap.xml in the docroot would be served instead of the route, so
 * any leftover copy is removed.
 */
class DailyHousekeeping extends Command
{
    private const SECTIONS = ['marketing', 'blog', 'restaurants'];

    protected $signature = 'cron:daily';

    protected $description = 'Daily housekeeping: refresh the cached sitemaps';

    public function handle(): int
    {
        $static = public_path('sitemap.xml');
        if (is_file($static)) {
            @unlink($static);
        }

        $keys = array_map(fn (string $section) => 'sitemap.'.$section, self::SECTIONS);
        $keys[] = 'sitemap.fusion:0';
        foreach (app(ResellerContext::class)->ids() as $resellerId) {
            $keys[] = 'sitemap.fusion:'.$resellerId;
        }

        foreach ($keys as $key) {
            Cache::forget($key);
        }
        SitemapHostCache::forgetRestaurants();

        return self::SUCCESS;
    }
}
