<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Models\StoreView;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * R1 — global period + compare control on the owner dashboard.
 * Verifies the selected reporting window actually drives the scan data,
 * end-to-end through the real /dashboard route and view.
 */
class OwnerDashboardPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        // Keep the stack (it shares $activeTheme with the view) but skip the install gate
        // (APP_INSTALLED=false in phpunit) and the locale prefix redirect (drops query params).
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);

        // The '*' view composer that shares these is gated behind env('APP_INSTALLED'),
        // which is false in phpunit — share them directly so the linen layout renders.
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function makeOwnerWithScans(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999]),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        $title = 'R '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 3 scans today, 5 scans three days ago.
        $this->seedScans($store->id, Carbon::now(), 3);
        $this->seedScans($store->id, Carbon::now()->subDays(3), 5);

        return $owner;
    }

    private function seedScans(int $storeId, Carbon $at, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            StoreView::create([
                'store_id' => $storeId,
                'ip' => '10.0.0.'.random_int(1, 254),
                'date' => $at->copy(),
                'device' => 'Mobile',
                'lang' => 'en',
            ]);
        }
    }

    public function test_default_period_is_month_and_compare_on(): void
    {
        $owner = $this->makeOwnerWithScans();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('dashboard-periodbar');
        $this->assertSame('month', $response->viewData('period'));
        $this->assertTrue($response->viewData('compare'));
    }

    public function test_today_period_scopes_scans_to_today(): void
    {
        $owner = $this->makeOwnerWithScans();

        $response = $this->actingAs($owner)->get(route('dashboard', ['period' => 'today']));

        $response->assertStatus(200);
        $this->assertSame('today', $response->viewData('period'));
        $this->assertSame(3, array_sum($response->viewData('scans')), 'only today\'s 3 scans in window');
    }

    public function test_custom_range_includes_both_days(): void
    {
        $owner = $this->makeOwnerWithScans();

        $response = $this->actingAs($owner)->get(route('dashboard', [
            'period' => 'custom',
            'from' => Carbon::now()->subDays(4)->toDateString(),
            'to' => Carbon::now()->toDateString(),
        ]));

        $response->assertStatus(200);
        $this->assertSame('custom', $response->viewData('period'));
        $this->assertSame(8, array_sum($response->viewData('scans')), 'today (3) + 3-days-ago (5) = 8');
    }

    public function test_compare_can_be_disabled(): void
    {
        $owner = $this->makeOwnerWithScans();

        $response = $this->actingAs($owner)->get(route('dashboard', ['compare' => '0']));

        $response->assertStatus(200);
        $this->assertFalse($response->viewData('compare'));
    }

    public function test_invalid_period_falls_back_to_month(): void
    {
        $owner = $this->makeOwnerWithScans();

        $response = $this->actingAs($owner)->get(route('dashboard', ['period' => 'garbage']));

        $response->assertStatus(200);
        $this->assertSame('month', $response->viewData('period'));
    }

    /** period=today follows the store's timezone: a scan on the store's "today"
     *  counts even when that instant is already "yesterday" on the platform. */
    public function test_today_period_uses_store_timezone(): void
    {
        $appTz = config('app.timezone') ?: 'UTC';
        // NY is on Aug 8 22:00 (EDT); the platform (Asia/Qatar) is already Aug 9 05:00.
        Carbon::setTestNow(Carbon::parse('2026-08-09 02:00:00', 'UTC'));

        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999]),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'timezone', 'America/New_York');

        // A scan at 10:00 New-York-time on Aug 8 — the store's "today", but the
        // platform calendar is already on Aug 9, so a platform-tz window misses it.
        $scanAt = Carbon::parse('2026-08-08 10:00:00', 'America/New_York')->setTimezone($appTz);
        StoreView::create(['store_id' => $store->id, 'ip' => '10.0.0.1', 'date' => $scanAt, 'device' => 'Mobile', 'lang' => 'en']);

        $response = $this->actingAs($owner)->get(route('dashboard', ['period' => 'today']));

        $response->assertStatus(200);
        $this->assertSame('today', $response->viewData('period'));
        $this->assertSame(1, array_sum($response->viewData('scans')), 'the NY-today scan is inside the store-tz window');

        Carbon::setTestNow();
    }
}
