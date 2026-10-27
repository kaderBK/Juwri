<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use Juwri\Core\Http\Middleware\Locale;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * R12 — customizable dashboard: per-user card show/hide (persisted in
 * user_options, no migration) + multi-branch comparison card.
 */
class OwnerDashboardLayoutTest extends TestCase
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

    public function test_topbar_notification_bell_wires_to_counts_endpoint(): void
    {
        [$owner] = $this->makeOwnerWithStore();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('notifBell(');
        $response->assertSeeHtml('notifBell');
        $response->assertSeeHtml(___('All clear'));
    }

    public function test_topbar_shows_authoritative_context_switcher(): void
    {
        [$owner, $store] = $this->makeOwnerWithStore();

        $response = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        // Topbar context button names the current store and posts the shared route.
        $response->assertSeeHtml('id="topbar-context"');
        $response->assertSeeHtml('context-store-select');
        $response->assertSeeHtml($store->title);
        // Write-through form carries the redirect_to hidden input.
        $response->assertSeeHtml('name="redirect_to"');
    }

    public function test_sidebar_footer_default_checkbox_does_not_autosubmit(): void
    {
        [$owner] = $this->makeOwnerWithStore();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        // Checkbox wired to the async helper, NOT a form auto-submit. The inline
        // onchange was converted to a data-lm-call hook (CSP dashboard-enforce prep,
        // driven by the old-theme.js delegated engine) — same async helper, no submit.
        $response->assertSeeHtml('data-lm-call="dashSaveDefault"');
        $response->assertSeeHtml('window.dashSaveDefault');
        $response->assertDontSeeHtml('onchange="this.form.submit()"');
        // Account row no longer shows a fake dropdown chevron.
        $response->assertDontSeeHtml('fi-rr-angle-down');
    }

    public function test_rail_tabs_render_visible_labels(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        [$owner] = $this->makeOwnerWithStore();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('rail-label');      // caption element present
        $response->assertSeeHtml('>Workspace<');     // a rail tab caption
        $response->assertSeeHtml('>Money<');         // P1-1 5-tab IA rail caption
        // Rail glyphs are kit SVGs (WI-16a), not icon-font classes.
        $this->assertMatchesRegularExpression('/data-ui-side-tab="[^"]+">\s*<svg/', $response->getContent());
    }

    public function test_nav_model_is_not_remembered_across_requests(): void
    {
        // Regression (flaky "rail tabs render visible labels" in the parallel sqlite slice): the nav model was
        // memoised in a process-wide static keyed by route name only, so a dashboard render under another
        // locale earlier in the same worker made later requests show those captions.
        [$owner] = $this->makeOwnerWithStore();

        app()->setLocale('ar');
        $this->actingAs($owner)->get(route('dashboard'))->assertStatus(200);
        $arabic = juwri_nav_model();

        app()->setLocale('en');
        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertStatus(200);
        $response->assertSeeHtml('>Workspace<');

        $this->assertNotSame($arabic[0]['title'], juwri_nav_model()[0]['title']);
    }

    public function test_topbar_search_renders_nav_inventory(): void
    {
        [$owner] = $this->makeOwnerWithStore();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        // Palette root present and wired to the shared nav model.
        $response->assertSeeHtml('id="topbar-search-root"');
        $response->assertSeeHtml('topbarSearch(');
        // Inventory blob carries known nav labels (so typing "coup" can surface Coupons).
        $response->assertSeeHtml('Coupons');
        $response->assertSeeHtml('Analytics');
    }

    public function test_saving_layout_hides_the_card(): void
    {
        [$owner] = $this->makeOwnerWithStore();

        // Keep everything except the live strip.
        $this->actingAs($owner)->post(route('dashboard.layout'), [
            'cards' => ['trend', 'topitems', 'breakdowns', 'attention'],
        ])->assertRedirect();

        $this->assertSame(['livestrip'], json_decode(UserOption::getUserOption($owner->id, 'dashboard_hidden_cards'), true));

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertStatus(200);
        $response->assertDontSeeHtml('Live tickets');   // live strip hidden
        $response->assertSeeHtml(___('Customize dashboard')); // control present
    }

    public function test_hidden_card_reappears_when_re_enabled(): void
    {
        [$owner] = $this->makeOwnerWithStore();
        UserOption::updateUserOption($owner->id, 'dashboard_hidden_cards', json_encode(['livestrip']));

        $this->actingAs($owner)->post(route('dashboard.layout'), [
            'cards' => ['livestrip', 'trend', 'topitems', 'breakdowns', 'attention'],
        ])->assertRedirect();

        $this->assertSame([], json_decode(UserOption::getUserOption($owner->id, 'dashboard_hidden_cards'), true));

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertSeeHtml(___('Live tickets'));
    }

    public function test_branch_comparison_shows_per_branch(): void
    {
        $this->requiresEditionPackage('the branch-comparison card is a chain tool (ee, owner 2026-10-05); Community asserts its absence in CommunityEditionSplitModulesTest');
        [$owner, $store] = $this->makeOwnerWithStore();
        $a = Branch::create(['store_id' => $store->id, 'name' => 'Alpha', 'active' => 1, 'accepting_orders' => 1, 'position' => 1]);
        $b = Branch::create(['store_id' => $store->id, 'name' => 'Beta', 'active' => 1, 'accepting_orders' => 1, 'position' => 2]);
        $mk = fn ($branchId) => ['store_id' => $store->id, 'branch_id' => $branchId, 'status' => 'completed', 'type' => 'dine_in', 'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now()];
        Order::insert([$mk($a->id), $mk($a->id), $mk($b->id), $mk($b->id), $mk($b->id)]);

        // scope to the whole store (all branches) so the comparison renders
        $response = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml(___('Branch comparison'));
        $cmp = collect($response->viewData('branchCard')['rows']);
        $this->assertSame(2, $cmp->firstWhere('name', 'Alpha')['orders']);
        $this->assertSame(3, $cmp->firstWhere('name', 'Beta')['orders']);
    }
}
