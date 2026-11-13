<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Controllers\User\DashboardController;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-289: store_tz() fallback chain — store option → first active branch tz
 * → app timezone. Also covers the dashboard "today" window for a Riyadh store
 * (the original bug report): at 22:30 UTC the Riyadh day starts at 00:30 local,
 * which in UTC is 00:30 the NEXT day, NOT the current one.
 */
class StoreTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A owner + store + a primary branch in the given timezone (the first
     * branch the resolver will find by position, id). position=1 so the
     * resolver picks it even when other branches exist.
     */
    private function makeStoreWithBranch(?string $branchTz): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($branchTz !== null) {
            Branch::create([
                'store_id' => $store->id,
                'name' => 'Riyadh',
                'active' => 1,
                'accepting_orders' => 1,
                'position' => 1,
                'timezone' => $branchTz,
            ]);
        }

        return [$owner, $store];
    }

    public function test_store_tz_uses_first_active_branch_timezone_when_no_store_option(): void
    {
        [, $store] = $this->makeStoreWithBranch('Asia/Riyadh');
        // No `timezone` option, primary branch is Asia/Riyadh → resolver falls
        // through to the branch.
        $this->assertSame('Asia/Riyadh', store_tz($store->id));
    }

    public function test_store_option_wins_over_branch_timezone(): void
    {
        [, $store] = $this->makeStoreWithBranch('Asia/Riyadh');
        StoreOption::updateStoreOption($store->id, 'timezone', 'Europe/London');
        $this->assertSame('Europe/London', store_tz($store->id));
    }

    public function test_invalid_stored_timezone_falls_back_to_branch(): void
    {
        [, $store] = $this->makeStoreWithBranch('Asia/Riyadh');
        // Stored value is not in timezone_identifiers_list() — must be ignored
        // and the branch timezone returned.
        StoreOption::updateStoreOption($store->id, 'timezone', 'Not/AZone');
        $this->assertSame('Asia/Riyadh', store_tz($store->id));
    }

    public function test_no_branch_tz_falls_back_to_app_timezone(): void
    {
        // No primary branch at all (branchTz=null → no branch created).
        [, $store] = $this->makeStoreWithBranch(null);
        $this->assertSame((string) (config('app.timezone') ?: 'UTC'), store_tz($store->id));
    }

    public function test_empty_branch_tz_falls_back_to_app_timezone(): void
    {
        // A branch exists but its timezone column is blank — resolver should
        // skip it (per spec: "non-empty valid timezone") and fall through.
        [, $store] = $this->makeStoreWithBranch(null);
        Branch::create([
            'store_id' => $store->id,
            'name' => 'No-Tz',
            'active' => 1,
            'accepting_orders' => 1,
            'position' => 1,
            'timezone' => '',
        ]);
        $this->assertSame((string) (config('app.timezone') ?: 'UTC'), store_tz($store->id));
    }

    public function test_inactive_branch_is_ignored(): void
    {
        // An inactive branch in Asia/Riyadh must be skipped; the active one in
        // Europe/London is picked (first active by position, id).
        [, $store] = $this->makeStoreWithBranch(null);
        Branch::create([
            'store_id' => $store->id, 'name' => 'Inactive Riyadh', 'active' => 0,
            'accepting_orders' => 1, 'position' => 1, 'timezone' => 'Asia/Riyadh',
        ]);
        Branch::create([
            'store_id' => $store->id, 'name' => 'Active London', 'active' => 1,
            'accepting_orders' => 1, 'position' => 2, 'timezone' => 'Europe/London',
        ]);
        $this->assertSame('Europe/London', store_tz($store->id));
    }

    public function test_first_active_branch_is_picked_by_position_then_id(): void
    {
        // Three active branches — the resolver must take the smallest position;
        // ties broken by id (the spec calls it out explicitly).
        [, $store] = $this->makeStoreWithBranch(null);
        Branch::create([
            'store_id' => $store->id, 'name' => 'C', 'active' => 1,
            'accepting_orders' => 1, 'position' => 5, 'timezone' => 'Europe/London',
        ]);
        Branch::create([
            'store_id' => $store->id, 'name' => 'A', 'active' => 1,
            'accepting_orders' => 1, 'position' => 1, 'timezone' => 'Asia/Riyadh',
        ]);
        Branch::create([
            'store_id' => $store->id, 'name' => 'B', 'active' => 1,
            'accepting_orders' => 1, 'position' => 2, 'timezone' => 'Asia/Dubai',
        ]);
        $this->assertSame('Asia/Riyadh', store_tz($store->id));
    }

    public function test_dashboard_today_window_for_riyadh_store_at_22_30_utc(): void
    {
        // P6-289 dashboard example: frozen 22:30 UTC = 01:30 Riyadh (UTC+3, no
        // DST). The Riyadh day is the NEXT calendar day (Jul 16), so the
        // today window in app-tz (UTC on the test runner) starts at Riyadh
        // 2026-07-16 00:00 local = 2026-07-15 21:00 UTC — NOT at UTC midnight
        // (the old UTC-walked behaviour). The resolver reaches the branch
        // fallback because no store-level option is written.
        [, $store] = $this->makeStoreWithBranch('Asia/Riyadh');
        Carbon::setTestNow(Carbon::parse('2026-07-15 22:30:00', 'UTC'));

        $reflection = new \ReflectionClass(DashboardController::class);
        $method = $reflection->getMethod('todayWindow');
        $method->setAccessible(true);
        [$start, $end] = $method->invoke(null, (int) $store->id);

        // Riyadh 2026-07-16 00:00 local = 2026-07-15 21:00 UTC (app tz).
        $this->assertSame('2026-07-15 21:00:00', $start->format('Y-m-d H:i:s'));
        // Riyadh 2026-07-16 23:59:59 local = 2026-07-16 20:59:59 UTC.
        $this->assertSame('2026-07-16 20:59:59', $end->format('Y-m-d H:i:s'));
    }
}
