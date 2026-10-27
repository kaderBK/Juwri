<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Services\Gbp\FakeGbpEngagementClient;
use Juwri\Modules\Integrations\Services\Gbp\GbpEngagementClientInterface;
use Juwri\Modules\Integrations\Services\Gbp\GbpPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane B / B5: performance summary rollup, daily cache, empty state.
 */
class GbpPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_gbp_link' => true]);
        FakeGbpEngagementClient::reset();
        app()->instance(GbpEngagementClientInterface::class, new FakeGbpEngagementClient());
    }

    private function connectedStore(string $locationId = 'LOC-1'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['location_id' => $locationId, 'account_id' => 'ACC-1', 'access_token' => 'AT'],
        ]);

        return $r;
    }

    public function test_summary_rolls_up_totals(): void
    {
        $r = $this->connectedStore();
        $summary = app(GbpPerformanceService::class)->monthlySummary($r->id);

        $this->assertTrue($summary['available']);
        $this->assertSame(30, $summary['range_days']);
        // Fake yields (strlen(metric)%9)+1 per day over 31 inclusive days (capped 45).
        // Views = sum of the 4 impression metrics; each is >0, so views must be > any single metric.
        $this->assertGreaterThan(0, $summary['views']);
        $this->assertGreaterThan(0, $summary['calls']);
        $this->assertGreaterThan(0, $summary['directions']);
        $this->assertGreaterThan(0, $summary['website_clicks']);
        $this->assertGreaterThan($summary['calls'], $summary['views']); // 4 impressions summed
    }

    public function test_result_is_cached_daily(): void
    {
        $r = $this->connectedStore();
        $first = app(GbpPerformanceService::class)->monthlySummary($r->id);
        $this->assertTrue(Cache::has("gbp_perf:{$r->id}"));

        // Swap in a client that EXPLODES if called — a cached read must not hit it.
        app()->instance(GbpEngagementClientInterface::class, new class extends FakeGbpEngagementClient {
            public function fetchPerformanceReport(string $locationId, array $metrics, array $range): array
            {
                throw new \RuntimeException('should not be called — must serve from cache');
            }
        });

        $second = app(GbpPerformanceService::class)->monthlySummary($r->id);
        $this->assertSame($first, $second);
    }

    public function test_empty_state_when_not_connected(): void
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $summary = app(GbpPerformanceService::class)->monthlySummary($r->id);
        $this->assertFalse($summary['available']);
        $this->assertSame(0, $summary['views']);
        $this->assertFalse(Cache::has("gbp_perf:{$r->id}")); // no cache write on the dark path
    }
}
