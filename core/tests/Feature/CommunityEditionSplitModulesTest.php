<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Controllers\User\DashboardController;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Theme;
use App\Services\AppInstallService;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\ThemePurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Editions\Edition;
use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Http\Middleware\TrackOwnerScreenView;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Core\Providers\CoreServiceProvider;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\ScheduleSlots;
use Juwri\Modules\Agents\Contracts\AgentPolicyStore;
use Juwri\Modules\Agents\Services\Agents\StoreAgentPolicies;
use Juwri\Modules\Analytics\Contracts\BranchComparison;
use Juwri\Modules\Analytics\Http\Controllers\User\AnalyticsCopilotController;
use Juwri\Modules\Analytics\Http\Controllers\User\BranchReportController;
use Juwri\Modules\Analytics\Services\NoBranchComparison;
use Juwri\Modules\AppStore\Services\AppBillingService;
use Juwri\Modules\Auth\Contracts\SsoProvider;
use Juwri\Modules\Auth\Services\Sso\NoSsoProvider;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Providers\FiscalServiceProvider;
use Juwri\Modules\Fiscal\Services\FiscalDriverManager;
use Juwri\Modules\Fiscal\Services\PintAe\PintAeAdapter;
use Juwri\Modules\Inventory\Models\BranchStock;
use Juwri\Modules\Marketing\Providers\MarketingServiceProvider;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\CommunityTestCase;

/**
 * P1-13 13h: the ee halves of the split modules are absent without a licence, and the Community halves keep working.
 *
 * AppStore: the partner dashboard (paid app/theme publishing, earnings), the admin app review queue and the seller
 * cash-out queue are ee; installing apps/themes, re-consent to an installed app's new scopes, the catalog editor, the
 * theme review queue (the operator's Theme Studio themes publish through it) and app billing — including the
 * revenue-share credit inside each sale, so a partner is never left unpaid while the licence is away — stay.
 *
 * Analytics: the per-branch report shows one branch at a time (owner decision); comparing every branch side by side
 * and the cross-business peer benchmarks (nightly aggregation, opt-in, dashboard card, copilot answer) are ee.
 *
 * Fiscal (incl. certified e-invoicing network connectors) and Marketing's automations are Community — nothing gated.
 * Agents and Auth: org-wide agent governance and SSO/SAML/SCIM have no code yet; their extension points
 * (AgentPolicyStore, SsoProvider) answer with the Community defaults (per-store rules; no SSO).
 *
 * (A separate class from CommunityEditionBootTest so the lanes' boot-test hunks do not collide.)
 */
class CommunityEditionSplitModulesTest extends CommunityTestCase
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
        $this->withoutMiddleware([Installed::class, Locale::class, PreventRequestForgery::class, TrackOwnerScreenView::class]);
        $this->shareLayoutGlobals();
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    public function test_app_store_ee_routes_are_absent_and_the_install_side_stays(): void
    {
        $this->assertTrue(app(Edition::class)->isCommunity());

        foreach (['partner.overview', 'partner.earnings', 'partner.earnings.statement', 'partner.reviews.reply',
            'partner.themes.create', 'partner.themes.store', 'partner.themes.edit', 'partner.apps.create', 'partner.apps.store',
            'partner.apps.edit', 'partner.apps.rotate-secret', 'partner.sandbox', 'partner.apps.media',
            'admin.app-reviews.index', 'admin.app-reviews.approve', 'admin.app-reviews.delist',
            'admin.withdrawals.index', 'admin.withdrawals.pay', 'admin.withdrawals.reject'] as $name) {
            $this->assertFalse(Route::has($name), "ee route $name must be absent in Community");
        }
        foreach (['apps.store.index', 'apps.store.show', 'apps.store.review', 'user.store.apps.reconsent', 'user.store.install.app',
            'user.store.buy.theme', 'user.store.buy.app.card', 'marketplace.buy', 'admin.app-catalog.index', 'admin.app-catalog.update',
            'admin.theme-reviews.index', 'admin.theme-reviews.approve', 'admin.theme-reviews.listing', 'admin.reviews.index'] as $name) {
            $this->assertTrue(Route::has($name), "Community route $name must stay");
        }
        $this->assertSame(
            'Juwri\Modules\AppStore\Http\Controllers\User\AppsController@reconsent',
            Route::getRoutes()->getByName('user.store.apps.reconsent')->getActionName()
        );
        // Same owner-only fence as before the split (the group now lives in routes/web.php).
        $middleware = Route::getRoutes()->getByName('user.store.apps.reconsent')->gatherMiddleware();
        $this->assertSame(['staff.dashboard', 'auth'], array_values(array_intersect($middleware, ['staff.dashboard', 'auth'])));
    }

    public function test_an_owner_reconsents_to_an_installed_apps_new_scopes(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $app = App::create(['name' => 'Free App', 'slug' => 'free-app', 'scopes' => ['menu:read'], 'status' => 'approved', 'price' => 0]);
        $installation = new AppInstallService()->install($app, $owner);
        $app->update(['scopes' => ['menu:read', 'orders:read']]);

        $this->actingAs($owner)->from('/apps')->post(route('user.store.apps.reconsent', $app->id))->assertRedirect('/apps');

        $this->assertSame(['menu:read', 'orders:read'], $installation->fresh()->granted_scopes);
    }

    public function test_paid_sales_still_credit_the_partner_without_a_licence(): void
    {
        $partner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        WalletService::credit($owner, 100, 'seed');

        $theme = Theme::create(['name' => 'Paid', 'slug' => 'paid-theme', 'status' => 'published', 'price' => 40, 'seller_id' => $partner->id]);
        new ThemePurchaseService()->purchase($owner, $theme);
        $this->assertSame(28.0, WalletService::balanceFor($partner)); // 40 × 0.70, as licensed

        $app = App::create([
            'name' => 'Dev App', 'slug' => 'dev-app', 'scopes' => ['menu:read'], 'status' => 'approved',
            'developer_user_id' => $partner->id, 'billing_type' => 'recurring', 'price' => 10, 'billing_period_days' => 30,
        ]);
        new AppInstallService()->install($app, $owner);
        AppInstallation::query()->update(['current_period_ends_at' => now()->subDay()]);
        new AppBillingService()->renewDue();

        $this->assertSame(42.0, WalletService::balanceFor($partner)); // + 7 install + 7 renewal
        $this->assertSame(40.0, WalletService::balanceFor($owner));   // 100 − 40 − 10 − 10
    }

    public function test_admin_and_developer_pages_do_not_link_the_ee_app_store_parts(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);

        $html = (string) $this->actingAs($admin)->get(route('admin.app-catalog.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('/admin/app-reviews', $html);
        $this->actingAs($admin)->get(route('admin.theme-reviews.index'))->assertOk();
        $html = (string) $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('/admin/withdrawals', $html);
        $this->assertStringNotContainsString('/admin/app-reviews', $html);

        $html = (string) $this->get(route('developers.apps'))->assertOk()->getContent();
        $this->assertStringNotContainsString('/partner/apps/create', $html);
        $this->assertStringContainsString('partner dashboard', $html);
    }

    public function test_the_branch_report_shows_one_branch_at_a_time(): void
    {
        $this->assertInstanceOf(NoBranchComparison::class, app(BranchComparison::class));
        [$owner, $store] = $this->ownerWithStore();
        $main = Branch::where('store_id', $store->id)->first();
        $second = Branch::create(['store_id' => $store->id, 'name' => 'Second', 'active' => true, 'position' => 1]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => '1']);
        foreach ([[$main, 2], [$second, 1]] as [$branch, $qty]) {
            $order = Order::create(['store_id' => $store->id, 'branch_id' => $branch->id, 'type' => 'pickup', 'customer_name' => 'X', 'status' => 'completed', 'created_at' => now()]);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => $qty, 'variation' => 0]);
            $order->load('items');
            $order->snapshotTotals(); // P6-322: reports read the frozen grand_total
        }
        $this->actingAs($owner);

        $data = (new BranchReportController)->index(Request::create(route('branches.reports'), 'GET', ['store_id' => $store->id]))->getData();
        $this->assertFalse($data['compare']);
        $this->assertSame([$main->id], $data['rows']->map(fn ($row) => $row->branch->id)->all(), 'the first branch by default');
        $this->assertEquals(['orders' => 1, 'revenue' => 20.0], $data['totals']);

        $data = (new BranchReportController)->index(Request::create(route('branches.reports'), 'GET', ['store_id' => $store->id, 'branch_id' => $second->id]))->getData();
        $this->assertSame([$second->id], $data['rows']->map(fn ($row) => $row->branch->id)->all());
        $this->assertEquals(['orders' => 1, 'revenue' => 10.0], $data['totals']);

        $html = (string) $this->get(route('branches.reports', ['store_id' => $store->id, 'branch_id' => $second->id]))->assertOk()->getContent();
        $this->assertStringContainsString('name="branch_id"', $html);
        $this->assertStringContainsString('<p class="ui-overline">Second</p>', $html);
        $this->assertStringNotContainsString('<p class="ui-overline">'.___('All branches').'</p>', $html);
        $this->assertStringNotContainsString(___('Branch comparison'), $html);
    }

    public function test_peer_benchmarks_are_absent(): void
    {
        $this->assertFalse(Route::has('dashboard.benchmarkOptIn'));
        $this->assertArrayNotHasKey('benchmarks:compute', Artisan::all());
        $this->assertSame([], ScheduleSlots::names('after:voice-notes:prune'));
        $this->assertFalse(Hooks::has(DashboardController::PEER_BENCHMARK_SLOT));
        $this->assertFalse(Hooks::has(AnalyticsCopilotController::PUBLISHED_BENCHMARK_SLOT));
        $packages = array_column(EditionPackages::all(), 'path');
        foreach (CoreServiceProvider::migrationPaths() as $dir) {
            $real = realpath($dir) ?: $dir;
            foreach ($packages as $package) {
                if (str_starts_with($real, $package.'/')) {
                    continue 2;   // an installed edition package's migrations (not Community's)
                }
            }
            foreach (glob($dir.'/*.php') ?: [] as $file) {
                $this->assertStringNotContainsString("Schema::create('peer_benchmarks'", (string) file_get_contents($file), basename($file));
            }
        }

        // An opted-in owner with the AI assistant on: the dashboard has no "vs similar stores" card, settings no opt-in.
        config(['settings.admin_allow_ai_assistant' => true]);
        [$owner, $store] = $this->ownerWithStore();
        UserOption::updateUserOption($owner->id, 'peer_benchmark_opt_in', '1');
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'))->assertOk()->assertDontSeeHtml('vs similar stores');
        $this->actingAs($owner)->get(route('settings'))->assertOk()->assertDontSeeHtml('benchmark-opt-in');
    }

    public function test_fiscal_and_marketing_automations_are_community(): void
    {
        // Fiscal: e-invoicing network connectors are Community (owner, 2026-10-03) — provider, routes, adapters, no ee wording.
        $this->assertNotNull(app()->getProvider(FiscalServiceProvider::class));
        foreach (['fiscal.index', 'fiscal.zatca.onboard', 'fiscal.credentials', 'fiscal.eta.device', 'webhook.fiscal.eta'] as $name) {
            $this->assertTrue(Route::has($name), "Community route $name");
        }
        config(['fiscal.adapters.AE.enabled' => true]);
        app()->forgetInstance(FiscalDriverManager::class);
        $adapter = app(FiscalDriverManager::class)->for('AE');
        $this->assertInstanceOf(PintAeAdapter::class, $adapter);
        $result = $adapter->submit(new FiscalDocument(['country' => 'AE']));
        $this->assertSame('pint_ae_access_point_missing', $result['response']['error']);
        $this->assertStringNotContainsString('(ee)', $result['response']['message']);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules/Fiscal'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $src = (string) file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/Contracts\\\\Editions\\\\Edition|->licensed\(/', $src, 'Fiscal is not edition-gated: '.$file->getFilename());
        }

        // Marketing: the automations page (cart recovery, win-back, birthday offers) is basic, single-step = Community.
        $this->assertNotNull(app()->getProvider(MarketingServiceProvider::class));
        $this->assertTrue(Route::has('stores.automations.index'));
        $this->assertTrue(Route::has('stores.automations.update'));
    }

    public function test_agent_governance_and_sso_extension_points_have_community_defaults(): void
    {
        $this->assertInstanceOf(StoreAgentPolicies::class, app(AgentPolicyStore::class));
        $this->assertInstanceOf(NoSsoProvider::class, app(SsoProvider::class));
        $this->assertFalse(app(SsoProvider::class)->ssoEnabled(1));
        $this->assertSame([], app(SsoProvider::class)->connections(1));
        $this->assertFalse(app(SsoProvider::class)->scimEnabled(1));
    }

    /**
     * Owner 2026-10-05 (chain tools): the owner-dashboard branch-comparison card is ee — Community shows no card even
     * with several branches, and does not even compute the numbers.
     */
    public function test_the_dashboard_has_no_branch_comparison_card(): void
    {
        $this->assertFalse(Hooks::has(DashboardController::BRANCH_CARD_SLOT));
        [$owner, $store] = $this->ownerWithStore();
        Branch::create(['store_id' => $store->id, 'name' => 'Alpha', 'active' => 1, 'accepting_orders' => 1, 'position' => 1]);
        Branch::create(['store_id' => $store->id, 'name' => 'Beta', 'active' => 1, 'accepting_orders' => 1, 'position' => 2]);
        Order::insert([['store_id' => $store->id, 'branch_id' => Branch::where('store_id', $store->id)->value('id'), 'status' => 'completed', 'type' => 'dine_in', 'customer_name' => 'C', 'phone_number' => '1', 'created_at' => now()]]);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])->get(route('dashboard'))->assertOk();

        $this->assertNull($response->viewData('branchCard'));
        $response->assertDontSee(___('Branch comparison'));
    }

    /**
     * Owner 2026-10-05 (chain tools): stock transfers between branches are ee. Community keeps single-branch inventory
     * whole (adjust, history) and shows no transfer control; a direct URL of the moved route is a 404.
     */
    public function test_stock_transfer_between_branches_is_absent_and_single_branch_inventory_works(): void
    {
        $this->assertFalse(Route::has('inventory.ingredients.transfer'));
        [$owner, $store] = $this->ownerWithStore();
        $main = Branch::where('store_id', $store->id)->first();
        $second = Branch::create(['store_id' => $store->id, 'name' => 'Second', 'active' => true, 'position' => 1]);
        $ing = Ingredient::create(['store_id' => $store->id, 'name' => 'Flour', 'unit' => 'kg']);
        $this->actingAs($owner);

        // Single-branch inventory still works end to end: add stock, see the page and the history.
        $this->post(url('inventory/ingredients/'.$ing->id.'/adjust'), ['direction' => 'in', 'quantity' => 5, 'branch_id' => $main->id])->assertRedirect();
        $this->assertSame(5.0, (float) BranchStock::where('branch_id', $main->id)->where('ingredient_id', $ing->id)->value('current_stock'));
        $html = (string) $this->get(route('inventory.ingredients.index', ['store_id' => $store->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Flour', $html);
        $this->get(route('inventory.ingredients.history', $ing->id))->assertOk();

        // No dead controls for the moved tool, even though the store has two branches.
        $this->assertStringNotContainsString(___('Transfer stock'), $html);
        $this->assertStringNotContainsString('ing-transfer', $html);

        // A direct URL of the moved route is a 404 and moves nothing.
        $this->post(url('inventory/ingredients/'.$ing->id.'/transfer'), ['from_branch_id' => $main->id, 'to_branch_id' => $second->id, 'quantity' => 2])->assertNotFound();
        $this->assertSame(5.0, (float) BranchStock::where('branch_id', $main->id)->where('ingredient_id', $ing->id)->value('current_stock'));
    }

    /** @return array{0: User, 1: Store} */
    private function ownerWithStore(): array
    {
        // 13g: no platform plans in Community — the owner runs on the edition plan.
        $owner = User::factory()->create(['group_id' => 'free', 'status' => 1, 'email_verified_at' => now()]);
        $title = 'S '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title,
            'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }
}
