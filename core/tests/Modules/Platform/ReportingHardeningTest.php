<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\SalesAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hr\Services\Rota\PayrollService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Reporting & analytics hardening:
 *  - CSV exports neutralize spreadsheet formula injection (storefront-controlled fields);
 *  - payroll tips count only real (paid) money;
 *  - the sales summary buckets by the store's timezone and never 500s on a bad range.
 */
class ReportingHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['app.timezone' => 'UTC']); // fix the platform tz so the store-tz assertion is deterministic
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    public function test_order_export_neutralizes_a_formula_injection_customer_name(): void
    {
        [$owner, $store] = $this->store();
        // A customer orders under a name that is a spreadsheet formula.
        Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1,
            'customer_name' => '=HYPERLINK("http://evil","open")', 'phone_number' => '55500',
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);

        $body = $this->actingAs($owner, 'web')
            ->get(route('stores.ordersExportCsv', $store->id))
            ->assertOk()
            ->streamedContent();

        // Neutralized: the dangerous cell is prefixed with ' so Excel/Sheets won't run it.
        $this->assertStringContainsString("'=HYPERLINK", $body, 'formula is neutralized');
        $this->assertStringNotContainsString('"=HYPERLINK', $body, 'no un-neutralized formula cell');
    }

    public function test_payroll_tips_count_only_paid_orders(): void
    {
        [$owner, $store] = $this->store();
        $staff = StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id, 'name' => 'Server',
            'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => 'waiter', 'active' => 1,
        ]);

        // One PAID order tipped $5, one UNPAID (abandoned) order tipped $5.
        foreach ([['is_paid' => 1], ['is_paid' => 0, 'status' => 'unpaid']] as $attrs) {
            Order::create(array_merge([
                'store_id' => $store->id, 'type' => 'on-table', 'status' => 'completed',
                'served_by_staff_id' => $staff->id, 'tip_amount' => 5.0,
                'track_token' => Str::random(16), 'created_at' => '2026-08-10 12:00:00',
            ], $attrs));
        }

        $report = (new PayrollService)->report($store->id, '2026-08-01', '2026-08-31');
        $row = collect($report['rows'])->firstWhere('staff_id', $staff->id);

        // Only the paid tip is owed; the unpaid/abandoned tip must not inflate payroll.
        $this->assertEqualsWithDelta(5.0, $row['tips'], 0.001);
    }

    public function test_sales_summary_buckets_by_the_store_timezone(): void
    {
        [$owner, $store] = $this->store();
        StoreOption::updateStoreOption($store->id, 'timezone', 'Asia/Manila'); // UTC+8

        // Placed 2026-08-11 01:00 Manila == 2026-08-10 17:00 UTC (stored in app tz).
        Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 1,
            'track_token' => Str::random(16), 'created_at' => '2026-08-10 17:00:00',
        ]);

        // Ask for "2026-08-11" — the store's local day. UTC-day bucketing dropped it.
        $summary = SalesAnalyticsService::summary($store->id, '2026-08-11', '2026-08-11');
        $this->assertSame(1, $summary['orders']['total'], 'the order on the store-local day is counted');
        $this->assertSame('2026-08-11', $summary['range']['from'], 'range echoes the store-local date asked');
    }

    public function test_sales_summary_survives_a_garbage_date_range(): void
    {
        [$owner, $store] = $this->store();

        // A malformed ?from/?to must not 500 (Carbon::parse would have thrown).
        $summary = SalesAnalyticsService::summary($store->id, 'not-a-date', 'xyz');
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('revenue', $summary);
    }
}
