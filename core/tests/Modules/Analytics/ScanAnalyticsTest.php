<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Models\StoreView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

class ScanAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
        $this->withoutExceptionHandling();
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

    public function test_legacy_range_param_redirects_to_period()
    {
        $merchant = User::factory()->create(['status' => 1]);
        $this->createTestRestaurant($merchant);

        // Old ?range= links redirect to the shared ?period= model (BC).
        $this->actingAs($merchant)
            ->get(route('analytics.index', ['range' => '30_days']))
            ->assertRedirect();

        $loc = $this->actingAs($merchant)
            ->get(route('analytics.index', ['range' => '30_days']))
            ->headers->get('Location');
        $this->assertStringContainsString('period=30d', $loc);
        $this->assertStringNotContainsString('range=', $loc);
    }

    public function test_scope_chip_reflects_context_store_and_scope_all()
    {
        $merchant = User::factory()->create(['status' => 1]);
        $a = $this->createTestRestaurant($merchant);
        $b = Store::create([
            'user_id' => $merchant->id, 'title' => 'Second Bistro', 'slug' => 'second-bistro',
            'color' => '#111', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '2 St', 'phone' => '2',
        ]);

        // Context on store A → chip names store A, offers "View all stores".
        $this->actingAs($merchant)
            ->withSession(['dashboard_store_id' => $a->id])
            ->get(route('analytics.index'))->assertStatus(200)->assertSeeHtml(___('Showing'))->assertSeeHtml('Pizza Paradise')->assertSeeHtml(___('View all stores'));

        // ?scope=all → all-stores copy + the hidden scope input that survives range changes.
        $this->actingAs($merchant)
            ->withSession(['dashboard_store_id' => $a->id])
            ->get(route('analytics.index', ['scope' => 'all']))->assertStatus(200)->assertSeeHtml(___('All stores'))->assertSeeHtml('name="scope" value="all"')->assertSeeHtml('across all your stores');
    }

    public function test_merchant_can_view_scan_analytics()
    {
        // The dashboard period defaults to the current calendar month, so freeze to
        // a mid-month midday instant: otherwise, on the 1st or 2nd, the subDays(1)
        // and subDays(2) fixtures below fall into the previous month, the page
        // renders its empty state, and every stat assertion fails. tearDown resets.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0, 0));

        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        // Create some scan logs
        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.1',
            'date' => now()->subDays(1)->format('Y-m-d 10:15:00'),
            'device' => 'Mobile',
            'lang' => 'en',
        ]);

        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.1', // Same IP (repeat visitor)
            'date' => now()->subDays(1)->format('Y-m-d 11:30:00'),
            'device' => 'Mobile',
            'lang' => 'en',
        ]);

        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.2', // Unique IP
            'date' => now()->subDays(2)->format('Y-m-d 15:45:00'),
            'device' => 'Tablet',
            'lang' => 'ar',
        ]);

        $this->actingAs($merchant);

        $response = $this->get(route('analytics.index'));
        $response->assertStatus(200);

        // Verify data rendered in the view
        $response->assertSee('Scans');
        $response->assertSee('Unique Visitors');
        $response->assertSee('Repeat Scanners');
        $response->assertSee('Repeat Rate');

        // Check values are present in HTML
        $response->assertSee('3'); // Scans
        $response->assertSee('2'); // Unique Visitors
        $response->assertSee('1'); // Repeat Scanners
        $response->assertSee('50%'); // Repeat Rate
    }

    /** Hourly buckets are shifted into the store's timezone (store_page_views.date is app-tz). */
    public function test_hourly_buckets_use_store_timezone(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0, 0));
        // The store↔platform hour shift AnalyticsController computes is relative to the
        // APP timezone, so pin it: the "-7h vs New York" math below assumes Asia/Qatar
        // (+3). CI leaves APP_TIMEZONE unset (= UTC), which would bucket at 08:00, not 05:00.
        config(['app.timezone' => 'Asia/Qatar']);

        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);
        StoreOption::updateStoreOption($store->id, 'timezone', 'America/New_York');

        // Recorded at 12:00 platform time — 05:00 in New York (EDT, -7h vs Asia/Qatar).
        StoreView::create([
            'store_id' => $store->id, 'ip' => '192.168.1.9',
            'date' => '2026-06-15 12:00:00', 'device' => 'Mobile', 'lang' => 'en',
        ]);

        $this->actingAs($merchant);
        $response = $this->get(route('analytics.index'));
        $response->assertStatus(200);

        $hourly = $response->viewData('hourlyData');
        $this->assertSame(1, $hourly['05:00'] ?? 0, 'bucketed at the New York hour');
        $this->assertSame(0, $hourly['12:00'] ?? 0, 'not at the platform hour');

        Carbon::setTestNow();
    }

    public function test_merchant_cannot_see_other_merchants_scans()
    {
        $merchant1 = User::factory()->create(['status' => 1]);
        $restaurant1 = $this->createTestRestaurant($merchant1);

        $merchant2 = User::factory()->create(['status' => 1]);
        $restaurant2 = $this->createTestRestaurant($merchant2);

        // Scan for merchant 2
        StoreView::create([
            'store_id' => $restaurant2->id,
            'ip' => '10.0.0.1',
            'date' => now()->format('Y-m-d H:i:s'),
            'device' => 'Mobile',
            'lang' => 'en',
        ]);

        // Request as Merchant 1
        $this->actingAs($merchant1);
        $response = $this->get(route('analytics.index'));
        $response->assertStatus(200);

        // Merchant 1 should see 0 stats
        $response->assertSee('0');
    }

    public function test_scan_analytics_shows_previous_period_trends_and_no_prior_data_states(): void
    {
        // Freeze to a mid-month, midday instant so period-window boundaries are
        // deterministic regardless of the CI timezone.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0, 0));

        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.1',
            'date' => now()->subDay()->format('Y-m-d 10:15:00'),
            'device' => 'Mobile',
            'lang' => 'en',
        ]);
        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.1',
            'date' => now()->subDay()->format('Y-m-d 11:30:00'),
            'device' => 'Mobile',
            'lang' => 'en',
        ]);
        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.2',
            'date' => now()->subDays(2)->format('Y-m-d 15:45:00'),
            'device' => 'Tablet',
            'lang' => 'ar',
        ]);

        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.9',
            'date' => now()->subDays(8)->format('Y-m-d 09:00:00'),
            'device' => 'Mobile',
            'lang' => 'en',
        ]);

        $this->actingAs($merchant);

        $response = $this->get(route('analytics.index', ['period' => '7d']));
        $response->assertOk();
        $response->assertSee('Unique Visitors');
        $this->assertMatchesRegularExpression('/\+100(?:\.0)?%/', $response->getContent());
        $response->assertSee('No prior data');
    }
}
