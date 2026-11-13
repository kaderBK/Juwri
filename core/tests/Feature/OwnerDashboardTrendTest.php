<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

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
 * R6 — sales & orders trend chart (server-rendered SVG) driven by the window.
 */
class OwnerDashboardTrendTest extends TestCase
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
            \App\Http\Middleware\Installed::class,
            \Juwri\Core\Http\Middleware\Locale::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function makeOwnerWithStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999]),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R ' . Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$owner, $store];
    }

    public function test_trend_card_renders_when_orders_exist(): void
    {
        [$owner, $store] = $this->makeOwnerWithStore();
        Order::insert([
            ['store_id' => $store->id, 'status' => 'completed', 'type' => 'dine_in', 'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now()],
            ['store_id' => $store->id, 'status' => 'completed', 'type' => 'dine_in', 'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now()],
            ['store_id' => $store->id, 'status' => 'completed', 'type' => 'dine_in', 'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now()],
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('Sales');
        $response->assertSeeHtml('trendchart');   // the SVG
        $response->assertSeeHtml('trend-legend');
    }

    public function test_trend_card_shows_empty_state_without_activity(): void
    {
        [$owner] = $this->makeOwnerWithStore();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('No sales yet');
        $response->assertDontSeeHtml('trendchart');
    }
}
