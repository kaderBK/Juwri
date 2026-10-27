<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Modules\Analytics\Mail\DailyAnomalyAlertMail;
use Juwri\Modules\Analytics\Mail\WeeklyDigestMail;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuItemEvent;
use Tests\Support\TestPlans;
use Tests\TestCase;

class AnalyticsExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $settings = new class(['theme_color' => '#111111', 'site_title' => 'juwri', 'meta_description' => 'Test description', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };

        $plan = TestPlans::create([
            'name' => 'Free Plan',
            'description' => 'Free membership plan',
            'monthly_price' => 0,
            'annual_price' => 0,
            'lifetime_price' => 0,
            'settings' => [
                'allow_viewonly' => '1',
                'allow_pro' => '1',
            ],
            'status' => 1,
        ]);
        config(['settings.free_membership_plan' => $plan]);

        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
            'plan' => $plan,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function createTestRestaurant(User $user): Store
    {
        return Store::create([
            'user_id' => $user->id,
            'title' => 'Pizza Paradise',
            'slug' => 'pizza-paradise',
            'color' => '#ff5500',
            'sub_title' => 'Test restaurant',
            'description' => 'Test description',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 Test Street',
            'phone' => '1234567890',
        ]);
    }

    public function test_merchant_can_export_analytics_csv()
    {
        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        $category = MenuCategory::create([
            'store_id' => $store->id,
            'name' => 'Pizza',
        ]);

        $item = Menu::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Margherita',
            'price' => 10,
        ]);

        // Create events
        MenuItemEvent::create([
            'menu_id' => $item->id,
            'store_id' => $store->id,
            'type' => 'view',
            'created_at' => now(),
        ]);

        $this->actingAs($merchant);

        $response = $this->get(route('analytics.export', ['range' => '7_days']));
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        // Capture streamed response content
        $content = $response->streamedContent();

        $this->assertStringContainsString('Analytics Report Overview', $content);
        $this->assertStringContainsString('Margherita', $content);
        $this->assertStringContainsString('10.00', $content);
    }

    public function test_export_ignores_an_out_of_range_range_parameter()
    {
        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Pizza']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Margherita', 'price' => 10, 'active' => '1']);

        // One paid order 400 days ago (outside any real window) and one today
        // (inside every bounded fallback window: 7_days / 30_days / this_month).
        foreach ([400, 0] as $daysAgo) {
            $order = Order::create([
                'store_id' => $store->id, 'customer_name' => 'D', 'type' => 'delivery',
                'status' => 'pending', 'is_paid' => 1, 'track_token' => Str::random(20),
                'created_at' => now()->subDays($daysAgo),
            ]);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);
        }

        // range=all used to leave $startDate null -> an ALL-TIME export counting both.
        $response = $this->actingAs($merchant)->get(route('analytics.export', ['range' => 'all']));
        $response->assertStatus(200);
        $content = $response->streamedContent();

        // The invalid range is rejected: only the recent order is counted (not both),
        // and the Range row never echoes the bogus "all".
        $this->assertStringContainsString('"Orders Count",1', $content);
        $this->assertStringNotContainsString('Range,All', $content);
        $this->assertStringNotContainsString('Range,all', $content);
    }

    public function test_weekly_digest_mail_is_sent_to_opted_in_merchants()
    {
        Mail::fake();

        $merchant = User::factory()->create(['status' => 1, 'email' => 'merchant@example.com']);
        $store = $this->createTestRestaurant($merchant);

        // Opt-in option
        UserOption::updateUserOption($merchant->id, 'weekly_digest_email', '1');

        $this->artisan('analytics:send-weekly-digest')
            ->assertExitCode(0);

        Mail::assertQueued(WeeklyDigestMail::class, function ($mail) use ($merchant) {
            return $mail->hasTo($merchant->email) && $mail->name === ($merchant->name ?: $merchant->username);
        });
    }

    public function test_weekly_digest_mail_is_not_sent_to_opted_out_merchants()
    {
        Mail::fake();

        $merchant = User::factory()->create(['status' => 1, 'email' => 'optout@example.com']);
        $store = $this->createTestRestaurant($merchant);

        // Opt-out option (explicitly set to 0)
        UserOption::updateUserOption($merchant->id, 'weekly_digest_email', '0');

        $this->artisan('analytics:send-weekly-digest')
            ->assertExitCode(0);

        Mail::assertNotQueued(WeeklyDigestMail::class);
    }

    public function test_anomaly_alert_mail_is_sent_once_for_large_scan_drop()
    {
        Mail::fake();

        $merchant = User::factory()->create(['status' => 1, 'email' => 'alerts@example.com']);
        $store = $this->createTestRestaurant($merchant);

        UserOption::updateUserOption($merchant->id, 'anomaly_alerts_email', '1');

        foreach (range(2, 8) as $daysAgo) {
            foreach (range(1, 10) as $scanNumber) {
                StoreView::create([
                    'store_id' => $store->id,
                    'ip' => "10.0.{$daysAgo}.{$scanNumber}",
                    'date' => now()->subDays($daysAgo)->setTime(12, 0, 0),
                    'device' => 'Mobile',
                    'lang' => 'en',
                ]);
            }
        }

        foreach (range(1, 2) as $scanNumber) {
            StoreView::create([
                'store_id' => $store->id,
                'ip' => "192.168.0.{$scanNumber}",
                'date' => now()->subDay()->setTime(11, 0, 0), // P6-250: yesterday, a full day
                'device' => 'Mobile',
                'lang' => 'en',
            ]);
        }

        $this->artisan('analytics:send-anomaly-alerts')
            ->assertExitCode(0);

        Mail::assertQueued(DailyAnomalyAlertMail::class, function ($mail) use ($merchant) {
            return $mail->hasTo($merchant->email)
                && $mail->currentScans === 2
                && $mail->baselineAverageScans === 10.0;
        });

        Mail::assertQueuedCount(1);

        Mail::fake();

        $this->artisan('analytics:send-anomaly-alerts')
            ->assertExitCode(0);

        Mail::assertNotQueued(DailyAnomalyAlertMail::class);
    }

    public function test_anomaly_alert_mail_respects_opt_out()
    {
        Mail::fake();

        $merchant = User::factory()->create(['status' => 1, 'email' => 'optout-alerts@example.com']);
        $store = $this->createTestRestaurant($merchant);

        UserOption::updateUserOption($merchant->id, 'anomaly_alerts_email', '0');

        foreach (range(1, 7) as $daysAgo) {
            foreach (range(1, 8) as $scanNumber) {
                StoreView::create([
                    'store_id' => $store->id,
                    'ip' => "172.16.{$daysAgo}.{$scanNumber}",
                    'date' => now()->subDays($daysAgo)->setTime(12, 0, 0),
                    'device' => 'Mobile',
                    'lang' => 'en',
                ]);
            }
        }

        StoreView::create([
            'store_id' => $store->id,
            'ip' => '172.16.0.1',
            'date' => now()->setTime(12, 0, 0),
            'device' => 'Mobile',
            'lang' => 'en',
        ]);

        $this->artisan('analytics:send-anomaly-alerts')
            ->assertExitCode(0);

        Mail::assertNotQueued(DailyAnomalyAlertMail::class);
    }
}
