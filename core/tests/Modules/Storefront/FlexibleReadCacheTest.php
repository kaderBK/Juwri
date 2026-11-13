<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Illuminate\Support\Facades\Cache;
use App\Services\Storefront\StorefrontMenuCache;
use Tests\TestCase;

/**
 * P1-17: read caches use Cache::flexible() (stale-while-revalidate) with the same keys and the same
 * invalidation as before: a bust / forget makes the next read rebuild.
 */
class FlexibleReadCacheTest extends TestCase
{
    public function test_menu_payload_is_cached_and_a_bust_rebuilds_it(): void
    {
        config(['perf.menu_cache_ttl' => 300]);
        $calls = 0;
        $build = function () use (&$calls) {
            $calls++;

            return ['n' => $calls];
        };

        $this->assertSame(['n' => 1], StorefrontMenuCache::remember(77, null, 'en', $build));
        $this->assertSame(['n' => 1], StorefrontMenuCache::remember(77, null, 'en', $build));
        $this->assertSame(1, $calls);

        StorefrontMenuCache::bust(77);

        $this->assertSame(['n' => 2], StorefrontMenuCache::remember(77, null, 'en', $build));
    }

    public function test_a_forgotten_flexible_key_is_rebuilt_not_served_stale(): void
    {
        $calls = 0;
        $read = function () use (&$calls) {
            return Cache::flexible('p117:probe', [60, 120], function () use (&$calls) {
                return ++$calls;
            });
        };

        $this->assertSame(1, $read());
        $this->assertSame(1, $read());
        Cache::forget('p117:probe');
        $this->assertSame(2, $read());
    }
}
