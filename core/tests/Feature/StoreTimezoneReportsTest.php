<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StoreOption;
use App\Services\Analytics\ForecastService;
use App\Support\StoreClock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Analytics\Services\RevenueDepthService;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-102: reports/exports bucket by the STORE calendar day (DST-safe), not the UTC day. */
class StoreTimezoneReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function store(string $tz): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $s = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($s->id, 'timezone', $tz);

        return $s;
    }

    private function orderAtUtc(Store $s, string $utc, array $extra = []): Order
    {
        return Order::create(array_merge([
            'store_id' => $s->id, 'status' => 'new', 'type' => 'takeaway', 'customer_name' => 'C',
            'created_at' => Carbon::parse($utc, 'UTC'),
        ], $extra));
    }

    public function test_day_window_is_23_and_25_hours_on_new_york_dst_days(): void
    {
        $s = $this->store('America/New_York');
        [$a, $b] = StoreClock::dayWindow($s->id, '2026-03-08'); // spring forward
        $this->assertSame(23 * 3600, $a->diffInSeconds($b, true) | 0);
        [$a, $b] = StoreClock::dayWindow($s->id, '2026-11-01'); // fall back
        $this->assertSame(25 * 3600, $a->diffInSeconds($b, true) | 0);
        [$a, $b] = StoreClock::dayWindow($s->id, '2026-07-01');
        $this->assertSame(24 * 3600, $a->diffInSeconds($b, true) | 0);
    }

    public function test_local_date_range_counts_orders_around_local_midnight(): void
    {
        $ny = $this->store('America/New_York');   // UTC-4 in July
        $dxb = $this->store('Asia/Dubai');         // UTC+4
        // NY 2026-07-01: 04:00Z .. 04:00Z next day
        $this->orderAtUtc($ny, '2026-07-01 03:59:00'); // NY Jun 30 23:59
        $this->orderAtUtc($ny, '2026-07-01 04:00:00'); // NY Jul 1 00:00
        $this->orderAtUtc($ny, '2026-07-02 03:59:00'); // NY Jul 1 23:59
        $this->orderAtUtc($ny, '2026-07-02 04:00:00'); // NY Jul 2 00:00
        // Dubai 2026-07-01: 2026-06-30 20:00Z .. 2026-07-01 20:00Z
        $this->orderAtUtc($dxb, '2026-06-30 19:59:00'); // before
        $this->orderAtUtc($dxb, '2026-06-30 20:00:00'); // Jul 1 00:00
        $this->orderAtUtc($dxb, '2026-07-01 19:59:00'); // Jul 1 23:59
        $this->orderAtUtc($dxb, '2026-07-01 20:00:00'); // Jul 2 00:00

        foreach ([$ny, $dxb] as $s) {
            $q = Order::where('store_id', $s->id);
            StoreClock::whereLocalDateBetween($q, 'created_at', $s->id, '2026-07-01', '2026-07-01');
            $this->assertSame(2, $q->count(), 'store '.$s->id);
        }
    }

    public function test_dst_days_bucket_all_orders_once(): void
    {
        $s = $this->store('America/New_York');
        $s = $this->store('America/New_York');
        // Fall back 2026-11-01: local day = 04:00Z .. 05:00Z next day (25 h).
        $this->orderAtUtc($s, '2026-11-01 03:59:00'); // Oct 31
        $this->orderAtUtc($s, '2026-11-01 04:00:00');
        $this->orderAtUtc($s, '2026-11-02 04:59:00'); // last minute of Nov 1 local
        $this->orderAtUtc($s, '2026-11-02 05:00:00'); // Nov 2
        $q = Order::where('store_id', $s->id);
        StoreClock::whereLocalDateBetween($q, 'created_at', $s->id, '2026-11-01', '2026-11-01');
        $this->assertSame(2, $q->count());

        // Spring forward 2026-03-08: local day = 05:00Z .. 04:00Z next day (23 h).
        $this->orderAtUtc($s, '2026-03-08 04:59:00');
        $this->orderAtUtc($s, '2026-03-08 05:00:00');
        $this->orderAtUtc($s, '2026-03-09 03:59:00');
        $this->orderAtUtc($s, '2026-03-09 04:00:00');
        $q = Order::where('store_id', $s->id);
        StoreClock::whereLocalDateBetween($q, 'created_at', $s->id, '2026-03-08', '2026-03-08');
        $this->assertSame(2, $q->count());
    }

    public function test_revenue_depth_uses_store_day(): void
    {
        $s = $this->store('Asia/Dubai');
        $this->orderAtUtc($s, '2026-06-30 19:59:00');
        $this->orderAtUtc($s, '2026-06-30 20:00:00');
        $this->orderAtUtc($s, '2026-07-01 19:59:00');
        $this->orderAtUtc($s, '2026-07-01 20:00:00');
        $r = RevenueDepthService::breakdown($s->id, '2026-07-01', '2026-07-01');
        $this->assertSame(2, (int) ($r['orders'] ?? $r['count'] ?? -1));
    }

    public function test_release_due_honours_store_tz_across_dst(): void
    {
        Queue::fake();
        $s = $this->store('America/New_York');
        // fire_at is naive store wall-clock. Now = 2026-03-08 07:00Z = 03:00 EDT (just after spring-forward).
        Carbon::setTestNow(Carbon::parse('2026-03-08 07:00:00', 'UTC'));
        $due = $this->orderAtUtc($s, '2026-03-08 00:00:00', ['scheduled_at' => '2026-03-08 04:00:00', 'fire_at' => '2026-03-08 01:59:00']);
        $notYet = $this->orderAtUtc($s, '2026-03-08 00:00:00', ['scheduled_at' => '2026-03-08 05:00:00', 'fire_at' => '2026-03-08 03:01:00']);
        // 02:30 does not exist on this day (gap): it resolves to 03:30 EDT, so it must NOT release early at 03:00.
        $gap = $this->orderAtUtc($s, '2026-03-08 00:00:00', ['scheduled_at' => '2026-03-08 05:00:00', 'fire_at' => '2026-03-08 02:30:00']);
        $this->assertSame(1, app(SchedulingService::class)->releaseDue());
        $this->assertNotNull($due->fresh()->released_at);
        $this->assertNull($notYet->fresh()->released_at);
        $this->assertNull($gap->fresh()->released_at);

        $s = $this->store('America/New_York');
        // Fall back 2026-11-01: 05:30Z = 01:30 EDT (first 1:30). 1:45 not due, 1:15 due.
        Carbon::setTestNow(Carbon::parse('2026-11-01 05:30:00', 'UTC'));
        $a = $this->orderAtUtc($s, '2026-11-01 00:00:00', ['scheduled_at' => '2026-11-01 03:00:00', 'fire_at' => '2026-11-01 01:15:00']);
        $b = $this->orderAtUtc($s, '2026-11-01 00:00:00', ['scheduled_at' => '2026-11-01 03:00:00', 'fire_at' => '2026-11-01 01:45:00']);
        app(SchedulingService::class)->releaseDue();
        $this->assertNotNull($a->fresh()->released_at);
        $this->assertNull($b->fresh()->released_at);
    }

    public function test_journal_default_date_is_store_local_date(): void
    {
        $s = $this->store('America/New_York');
        Carbon::setTestNow(Carbon::parse('2026-07-02 03:30:00', 'UTC')); // NY Jul 1 23:30
        $this->assertSame('2026-07-01', StoreClock::today($s->id));
        $this->assertSame('2026-07-01', StoreClock::localDate($s->id, Carbon::now()));
        $src = file_get_contents(base_path('app/Modules/Accounting/Services/Accounting/Gl/JournalEngine.php'));
        $this->assertStringContainsString('StoreClock::today($storeId)', $src);
        // month boundary: NY Jun 30 23:30 is Jul 1 UTC but must stay in June
        Carbon::setTestNow(Carbon::parse('2026-07-01 03:30:00', 'UTC'));
        $this->assertSame('2026-06-30', StoreClock::today($s->id));
    }

    public function test_forecast_hourly_curve_uses_local_hour(): void
    {
        $s = $this->store('America/New_York');
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00', 'UTC'));
        $this->orderAtUtc($s, '2026-07-10 02:30:00'); // 22:30 NY
        $m = new \ReflectionMethod(ForecastService::class, 'hourlyCurve');
        $curve = $m->invoke(app(ForecastService::class), $s->id, \Illuminate\Support\Carbon::parse('2026-07-01', 'UTC'), \Illuminate\Support\Carbon::parse('2026-07-20', 'UTC'));
        $this->assertSame(1.0, (float) $curve[22]);
    }

    public function test_invalid_timezone_falls_back_to_app_tz_and_reports_render(): void
    {
        $s = $this->store('Not/AZone');
        $this->assertSame(config('app.timezone') ?: 'UTC', StoreClock::tz($s->id));
        $this->orderAtUtc($s, '2026-07-01 10:00:00');
        $r = RevenueDepthService::breakdown($s->id, '2026-07-01', '2026-07-01');
        $this->assertSame(1, (int) $r['orders']);
    }
}
