<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\SalesAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * R3 — KPI row: Sales · Orders · Avg order value · Scans, each with sparklines.
 * Revenue correctness is delegated to the canonical SalesAnalyticsService and
 * cross-checked here, so the dashboard shares one revenue definition.
 */
class OwnerDashboardKpiTest extends TestCase
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

    private function seedOrders(int $storeId, int $count): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'store_id' => $storeId, 'status' => 'completed', 'type' => 'dine_in',
                'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now(),
            ];
        }
        Order::insert($rows);
    }

    public function test_home_quick_links_follow_context_store(): void
    {
        $owner = $this->makeOwner();
        $r1 = $this->makeStore($owner);
        $r2 = $this->makeStore($owner);

        $response = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $r2->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        // The Orders quick action must point at the CONTEXT store (r2), not the first store.
        $this->assertEquals($r2->id, $this->ordersQuickActionStoreId($response->getContent()));
    }

    public function test_home_quick_links_fall_back_to_first_store_on_scope_all(): void
    {
        // Single store: ?scope=all shows the normal home (the fleet table needs >1
        // store, P2-4), so quick links fall back to the first store.
        $owner = $this->makeOwner();
        $r1 = $this->makeStore($owner);

        $response = $this->actingAs($owner)
            ->get(route('dashboard', ['scope' => 'all']));

        $response->assertStatus(200);
        $this->assertEquals($r1->id, $this->ordersQuickActionStoreId($response->getContent()));
    }

    /** Extract the store id from the Orders quick-action anchor (the one labelled "Review recent orders"). */
    private function ordersQuickActionStoreId(string $html): ?int
    {
        if (preg_match('#href="[^"]*orders/(\d+)"[^>]*>.*?Review recent orders#s', $html, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public function test_kpi_row_shows_sales_orders_aov_and_hides_earnings_label(): void
    {
        $owner = $this->makeOwner();
        $this->makeStore($owner);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('Sales');
        $response->assertSeeHtml(___('Avg order value'));
        $response->assertSeeHtml(___('Plan billing to date'));
        $response->assertDontSeeHtml('>Earnings<');
    }

    public function test_orders_and_aov_wire_up(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        $this->seedOrders($store->id, 3);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $this->assertSame(3, $response->viewData('thisMonthOrders'));
        // No items on these orders → revenue 0 → AOV 0 (guarded division).
        $this->assertSame(0.0, (float) $response->viewData('salesRevenue'));
        $this->assertSame(0, (int) $response->viewData('avgOrderValue'));
        $this->assertSame(3, array_sum($response->viewData('ordersSeries')), 'orders land in day buckets');
    }

    /** AOV divides revenue by the REVENUE-counted order count, not every order placed. */
    public function test_aov_divides_by_revenue_counted_orders_only(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C']);
        $item = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 50, 'active' => 1,
        ]);

        // Two revenue-counting orders ($50 each = $100 revenue) ...
        foreach (range(1, 2) as $i) {
            $o = Order::create([
                'store_id' => $store->id, 'status' => 'completed', 'type' => 'dine_in',
                'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now(),
            ]);
            OrderItem::create(['order_id' => $o->id, 'item_id' => $item->id, 'quantity' => 1, 'variation' => 0]);
        }
        // ... and two that do NOT count as revenue (pending, unpaid).
        foreach (range(1, 2) as $i) {
            Order::create([
                'store_id' => $store->id, 'status' => 'pending', 'type' => 'dine_in',
                'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now(),
            ]);
        }

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertStatus(200);

        $this->assertSame(4, $response->viewData('thisMonthOrders'), 'all four orders show in the Orders KPI');
        $this->assertEqualsWithDelta(100.0, (float) $response->viewData('salesRevenue'), 0.001);
        // AOV = 100 / 2 revenue orders = 50, NOT 100 / 4 = 25.
        $this->assertEqualsWithDelta(50.0, (float) $response->viewData('avgOrderValue'), 0.001);
    }

    public function test_sparkline_series_align_to_chart_buckets(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        $this->seedOrders($store->id, 2);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $days = count($response->viewData('days'));
        $this->assertCount($days, $response->viewData('salesSeries'));
        $this->assertCount($days, $response->viewData('ordersSeries'));
        $this->assertCount($days, $response->viewData('scans'));
    }

    public function test_revenue_matches_canonical_sales_service(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        $this->seedOrders($store->id, 4);

        $response = $this->actingAs($owner)->get(route('dashboard', [
            'period' => 'custom',
            'from' => Carbon::now()->startOfMonth()->toDateString(),
            'to' => Carbon::now()->endOfDay()->toDateString(),
        ]));

        $response->assertStatus(200);
        $canonical = SalesAnalyticsService::summary(
            $store->id,
            Carbon::now()->startOfMonth()->toDateString(),
            Carbon::now()->toDateString(),
        );
        $this->assertSame(
            (float) $canonical['revenue']['gross'],
            (float) $response->viewData('salesRevenue'),
            'dashboard sales revenue matches the canonical SalesAnalyticsService',
        );
    }
}
