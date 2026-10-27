<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StoreOption;
use App\Models\StoreView;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Modules\Analytics\Mail\DailyAnomalyAlertMail;
use Juwri\Modules\Ordering\Http\Controllers\Admin\OrderAdminController;
use Tests\TestCase;

/** P6-250 scan-drop alert and P6-251 admin "orders today" use full, timezone-aware days. */
class P6250P6251DayBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Store} */
    private function merchant(?string $tz = null, string $email = 'm@example.test'): array
    {
        $owner = User::factory()->create(['status' => 1, 'email' => $email]);
        $store = Store::create(['user_id' => $owner->id, 'slug' => 's'.uniqid(), 'color' => '#111', 'title' => 'S', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        if ($tz) {
            StoreOption::updateStoreOption($store->id, 'timezone', $tz);
        }
        UserOption::updateUserOption($owner->id, 'anomaly_alerts_email', '1');

        return [$owner, $store];
    }

    private function views(Store $store, Carbon $at, int $n): void
    {
        foreach (range(1, $n) as $i) {
            StoreView::create(['store_id' => $store->id, 'ip' => "10.0.0.$i", 'date' => $at->copy()->setTimezone('UTC'), 'device' => 'Mobile', 'lang' => 'en']);
        }
    }

    public function test_normal_traffic_at_nine_utc_does_not_alert(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00', 'UTC'));
        [, $store] = $this->merchant();
        foreach (range(1, 8) as $d) {
            $this->views($store, Carbon::parse('2026-10-19 12:00:00', 'UTC')->subDays($d), 10);
        }
        $this->views($store, Carbon::parse('2026-10-19 02:00:00', 'UTC'), 3); // a partial morning

        $this->artisan('analytics:send-anomaly-alerts')->assertExitCode(0);

        Mail::assertNotQueued(DailyAnomalyAlertMail::class);
    }

    public function test_a_real_drop_yesterday_alerts(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00', 'UTC'));
        [$owner, $store] = $this->merchant();
        foreach (range(2, 8) as $d) {
            $this->views($store, Carbon::parse('2026-10-19 12:00:00', 'UTC')->subDays($d), 10);
        }
        $this->views($store, Carbon::parse('2026-10-18 12:00:00', 'UTC'), 2);

        $this->artisan('analytics:send-anomaly-alerts')->assertExitCode(0);

        Mail::assertQueued(DailyAnomalyAlertMail::class, fn ($m) => $m->hasTo($owner->email) && $m->currentScans === 2);
    }

    public function test_riyadh_store_day_boundary_differs_from_utc(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00', 'UTC'));
        [, $store] = $this->merchant('Asia/Riyadh');
        // Riyadh is UTC+3: 22:00 UTC on the 17th is 01:00 on the 18th, i.e. YESTERDAY for the store.
        foreach (range(2, 8) as $d) {
            $this->views($store, Carbon::parse('2026-10-19 09:00:00', 'UTC')->subDays($d), 10);
        }
        $this->views($store, Carbon::parse('2026-10-17 22:00:00', 'UTC'), 10); // store-day 18th: normal yesterday
        // by the UTC calendar yesterday (18th) would hold only 0 scans and alert; by Riyadh's it holds 10.

        $this->artisan('analytics:send-anomaly-alerts')->assertExitCode(0);

        Mail::assertNotQueued(DailyAnomalyAlertMail::class);
    }

    public function test_one_failing_recipient_does_not_stop_the_rest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00', 'UTC'));
        $stores = [];
        foreach (['a@example.test', 'b@example.test'] as $email) {
            [, $store] = $this->merchant(null, $email);
            foreach (range(2, 8) as $d) {
                $this->views($store, Carbon::parse('2026-10-19 12:00:00', 'UTC')->subDays($d), 10);
            }
            $stores[] = $store;
        }
        $sent = [];
        $mailer = \Mockery::mock(Factory::class);
        $mailer->shouldReceive('to')->andReturnUsing(function ($email) use (&$sent) {
            if ($email === 'a@example.test') {
                throw new \RuntimeException('bad address');
            }
            $sent[] = $email;

            return new class
            {
                public function send($m) {}
            };
        });
        Mail::swap($mailer);

        $this->artisan('analytics:send-anomaly-alerts')->assertExitCode(0);

        $this->assertSame(['b@example.test'], $sent);
    }

    public function test_orders_today_uses_the_platform_timezone_day(): void
    {
        config(['settings.timezone' => 'Asia/Riyadh']);
        Carbon::setTestNow(Carbon::parse('2026-10-19 23:30:00', 'UTC')); // 02:30 on the 20th in Riyadh
        [, $store] = $this->merchant();
        $mk = fn (string $at) => Order::create(['store_id' => $store->id, 'type' => 'pickup', 'customer_name' => 'X', 'status' => 'completed', 'created_at' => Carbon::parse($at, 'UTC')]);
        $mk('2026-10-19 21:30:00'); // 00:30 on the 20th in Riyadh: today
        $mk('2026-10-19 23:00:00'); // today
        $mk('2026-10-19 20:30:00'); // 23:30 on the 19th in Riyadh: yesterday

        $view = app(OrderAdminController::class)->index(request());

        $this->assertSame(2, $view->getData()['stats']['today']);
    }
}
