<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Http\Middleware\Installed;
use Juwri\Core\Http\Middleware\Locale;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P2-3 — reports hub + pinned favourites: the hub renders category cards, the pin
 * endpoint toggles + caps at 6, and pinned rows render above the sidebar tabs.
 */
class ReportsHubTest extends TestCase
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
        View::share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => (object) [], 'languages' => collect(), 'admin_languages' => collect(),
        ]);
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P '.Str::random(6), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1]);
        $o = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        Store::create(['user_id' => $o->id, 'slug' => 's-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'S', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);

        return $o;
    }

    public function test_reports_hub_renders_category_cards(): void
    {
        $o = $this->owner();
        $res = $this->actingAs($o)->get(route('reports.index'));
        $res->assertStatus(200);
        $res->assertSeeHtml('Reports');
        $res->assertSeeHtml('Sales');      // a category
        $res->assertSeeHtml('report-pin'); // pin control present
    }

    public function test_pin_endpoint_toggles_and_caps_at_six(): void
    {
        $o = $this->owner();

        // Pin a valid destination.
        $this->actingAs($o)->postJson(route('dashboard.pins'), ['key' => 'analytics.index'])
            ->assertOk()->assertJson(['ok' => true, 'pinned' => true]);
        $this->assertContains('analytics.index', json_decode(UserOption::getUserOption($o->id, 'nav_pins'), true));

        // Toggling again unpins.
        $this->actingAs($o)->postJson(route('dashboard.pins'), ['key' => 'analytics.index'])
            ->assertOk()->assertJson(['pinned' => false]);

        // Cap at 6.
        foreach (['analytics.index', 'crm.rfm', 'customers.index', 'referral.index', 'campaigns.index', 'staff.index', 'wallet.index'] as $k) {
            $this->actingAs($o)->postJson(route('dashboard.pins'), ['key' => $k]);
        }
        $this->assertLessThanOrEqual(6, count(json_decode(UserOption::getUserOption($o->id, 'nav_pins'), true)));
    }

    public function test_pinned_group_renders_above_sidebar_tabs(): void
    {
        $o = $this->owner();
        UserOption::updateUserOption($o->id, 'nav_pins', json_encode(['analytics.index']));

        $res = $this->actingAs($o)->get(route('dashboard'));
        $res->assertStatus(200);
        $res->assertSeeHtml('ui-side__nav--pins'); // WI-16a: kit sidebar pinned group
        $res->assertSeeHtml(___('Pinned'));
    }
}
