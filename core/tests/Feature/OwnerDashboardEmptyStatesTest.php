<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
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
 * R8 — zero/empty states. The scans-overview chart shows a designed empty
 * state (not a blank canvas) when there are no scans in the window; the
 * breakdown/trend/top-item empties are covered by their own tests.
 */
class OwnerDashboardEmptyStatesTest extends TestCase
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

    private function makeOwnerWithStore(): array
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

        return [$owner, $store];
    }

    public function test_scan_chart_shows_empty_state_with_no_scans(): void
    {
        [$owner] = $this->makeOwnerWithStore();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml(___('No scans in this period'));
        $response->assertDontSeeHtml('id="dash-scans"');
    }

    public function test_scan_chart_renders_canvas_with_scans(): void
    {
        [$owner, $store] = $this->makeOwnerWithStore();
        StoreView::create([
            'store_id' => $store->id, 'ip' => '10.0.0.1',
            'date' => Carbon::now(), 'device' => 'Mobile', 'lang' => 'en',
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('id="dash-scans"');
        $response->assertDontSeeHtml('No scans in this period');
    }

    public function test_empty_states_carry_a_primary_cta(): void
    {
        [$owner, $store] = $this->makeOwnerWithStore();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        // No-scans → context store's QR builder; no-sales/orders → menu builder.
        $response->assertSeeHtml('Open QR builder');
        $response->assertSeeHtml(___('Build your menu'));
        $response->assertSeeHtml('qr-designer'); // CTA href targets the QR builder route
    }

    public function test_promo_and_usage_cards_are_dismissible(): void
    {
        [$owner] = $this->makeOwnerWithStore();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml("localStorage.getItem('lamar.promo.reseller')");
    }
}
