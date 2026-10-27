<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Models\Feature;
use Tests\TestCase;

/**
 * P6-60: the plan-gated key list is read from the app cache across requests
 * (zero queries once warm), busted by any Feature write, and a DB failure still
 * falls back to the last-known-good set (fail closed).
 */
class PlanGatedFeatureCacheTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'p660_probe';

    protected function setUp(): void
    {
        parent::setUp();
        Feature::updateOrCreate(['key' => self::KEY], ['label' => 'P660', 'plan_gated' => true]);
    }

    private function nextRequest(): void
    {
        app()->forgetInstance('features.plan_gated');
    }

    public function test_warm_cache_runs_no_plan_gated_query(): void
    {
        $this->assertTrue(EnsureFeatureEnabled::planGated(self::KEY));
        $this->nextRequest();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertTrue(EnsureFeatureEnabled::planGated(self::KEY));
        $hits = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'plan_gated'));
        DB::disableQueryLog();

        $this->assertSame([], array_values($hits));
    }

    public function test_flipping_plan_gated_takes_effect_next_request(): void
    {
        $this->assertTrue(EnsureFeatureEnabled::planGated(self::KEY));

        Feature::where('key', self::KEY)->firstOrFail()->update(['plan_gated' => false]);
        $this->nextRequest();
        $this->assertFalse(EnsureFeatureEnabled::planGated(self::KEY));

        Feature::where('key', self::KEY)->firstOrFail()->update(['plan_gated' => true]);
        $this->nextRequest();
        $this->assertTrue(EnsureFeatureEnabled::planGated(self::KEY));
    }

    public function test_db_failure_keeps_last_known_good_set(): void
    {
        $this->assertTrue(EnsureFeatureEnabled::planGated(self::KEY));
        Cache::forget(EnsureFeatureEnabled::CACHE_KEY);
        $this->nextRequest();

        $default = DB::getDefaultConnection();
        config(['database.connections.p660_broken' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent/p660/broken.sqlite',
            'prefix' => '',
        ]]);
        try {
            DB::setDefaultConnection('p660_broken');
            $this->assertTrue(EnsureFeatureEnabled::planGated(self::KEY), 'gate must stay closed on DB failure');
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    public function test_raw_write_is_picked_up_after_clear_cache(): void
    {
        $this->assertTrue(EnsureFeatureEnabled::planGated(self::KEY));

        DB::table('features')->where('key', self::KEY)->update(['plan_gated' => false]);
        FeatureRegistry::clearCache();

        $this->assertFalse(EnsureFeatureEnabled::planGated(self::KEY));
    }
}
