<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Http\Middleware\Installed;
use Juwri\Core\Http\Middleware\Locale;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-H10: the dashboard now shares one revenue definition and surfaces an
 * un-backfilled window instead of showing a quietly-low number.
 */
class DashboardRevenueParityTest extends TestCase
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
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999]),
            'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeStore(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_unsnapshotted_order_raises_the_revenue_gap_and_renders_the_notice(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);

        // A revenue-counting order (completed) created now, with NO snapshot — a
        // legacy row that revenueSum() cannot see.
        Order::insert([
            'store_id' => $store->id, 'status' => 'completed', 'type' => 'takeaway',
            'customer_name' => 'C', 'phone_number' => '1', 'is_paid' => 1,
            'grand_total_minor' => null, 'subtotal_minor' => null, 'tax_total_minor' => null,
            'created_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $response->viewData('revenueGap'));
        $response->assertSeeHtml(___('Sales figures are still being backfilled'));
    }

    public function test_a_fully_snapshotted_window_shows_no_gap_notice(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);

        Order::insert([
            'store_id' => $store->id, 'status' => 'completed', 'type' => 'takeaway',
            'customer_name' => 'C', 'phone_number' => '1', 'is_paid' => 1,
            'grand_total_minor' => 1000, 'subtotal_minor' => 1000, 'tax_total_minor' => 0, 'currency_code' => 'USD',
            'created_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk();
        $this->assertSame(0, (int) $response->viewData('revenueGap'));
        $response->assertDontSeeHtml(___('Sales figures are still being backfilled'));
    }
}
