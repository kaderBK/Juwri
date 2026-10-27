<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Analytics\Http\Controllers\User\BranchReportController;
use Juwri\Modules\Analytics\Services\BranchReport;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class BranchReportTest extends TestCase
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
        $this->withoutMiddleware();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function orderAtBranch(Store $r, Branch $b, Menu $item, int $qty): void
    {
        $o = Order::create(['store_id' => $r->id, 'branch_id' => $b->id, 'type' => 'pickup', 'customer_name' => 'X', 'status' => 'completed', 'created_at' => now()]);
        OrderItem::create(['order_id' => $o->id, 'item_id' => $item->id, 'quantity' => $qty, 'variation' => 0]);
        $o->load('items');
        $o->snapshotTotals();
    }

    /** Reports aggregate orders + revenue per branch (route forbids foreign restaurant) */
    public function test_reports_authorise_and_aggregate(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $main = Branch::where('store_id', $r->id)->first();
        $second = Branch::create(['store_id' => $r->id, 'name' => 'Second', 'active' => true, 'position' => 1]);

        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => '1']);

        $this->orderAtBranch($r, $main, $item, 2);   // revenue 20
        $this->orderAtBranch($r, $second, $item, 1); // revenue 10

        // Foreign restaurant rejected
        $intruder = $this->makeUser();
        $this->actingAs($intruder)->get(route('branches.reports', ['store_id' => $r->id]))->assertForbidden();

        // Controller logic check: aggregate via the same path
        $controller = new BranchReportController;
        $this->actingAs($owner);
        $request = Request::create(route('branches.reports'), 'GET', ['store_id' => $r->id, 'days' => 30]);
        $view = $controller->index($request);
        $data = $view->getData();

        $this->assertEquals(2, $data['rows']->count());
        $this->assertEquals(30.0, $data['totals']['revenue']);
        $this->assertEquals(2, $data['totals']['orders']);
    }

    /** Revenue counts only revenue-orders; a cancelled order is excluded from both revenue and the order count. */
    public function test_revenue_excludes_non_revenue_orders(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $main = Branch::where('store_id', $r->id)->first();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => '1']);

        $this->orderAtBranch($r, $main, $item, 2); // completed → revenue 20
        // A cancelled order with a would-be $50 total — must not reach revenue.
        $cancelled = Order::create([
            'store_id' => $r->id, 'branch_id' => $main->id, 'type' => 'pickup',
            'customer_name' => 'X', 'status' => 'cancelled', 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $cancelled->id, 'item_id' => $item->id, 'quantity' => 5, 'variation' => 0]);
        $cancelled->load('items');
        $cancelled->snapshotTotals();

        $controller = new BranchReportController;
        $this->actingAs($owner);
        $request = Request::create(route('branches.reports'), 'GET', ['store_id' => $r->id, 'days' => 30]);
        $data = $controller->index($request)->getData();

        $this->assertEqualsWithDelta(20.0, $data['totals']['revenue'], 0.001, 'cancelled order excluded from revenue');
        $this->assertSame(1, $data['totals']['orders'], 'cancelled orders are not orders placed');
    }

    /** The "last N days" window starts on the store's clock, not the platform's. */
    public function test_window_uses_store_timezone(): void
    {
        $appTz = config('app.timezone') ?: 'UTC';
        // Platform (Asia/Qatar) vs New York differ by 7h, so their day boundaries —
        // and therefore the start of a "last 7 days" window — sit 7h apart.
        Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00', 'UTC'));

        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        StoreOption::updateStoreOption($r->id, 'timezone', 'America/New_York');
        $branch = Branch::where('store_id', $r->id)->first();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => '1']);

        // Control: clearly inside the window.
        $this->orderAtBranch($r, $branch, $item, 1);

        // Edge: 2026-08-02 01:00 UTC — after the platform window start but before the
        // New-York one, so a store-tz window must exclude it (a platform-tz window
        // would wrongly count it).
        $edge = Order::create([
            'store_id' => $r->id, 'branch_id' => $branch->id, 'type' => 'pickup',
            'customer_name' => 'X', 'status' => 'completed',
            'created_at' => Carbon::parse('2026-08-02 01:00:00', 'UTC')->setTimezone($appTz),
        ]);
        OrderItem::create(['order_id' => $edge->id, 'item_id' => $item->id, 'quantity' => 5, 'variation' => 0]);

        $controller = new BranchReportController;
        $this->actingAs($owner);
        $request = Request::create(route('branches.reports'), 'GET', ['store_id' => $r->id, 'days' => 7]);
        $data = $controller->index($request)->getData();

        $this->assertEquals(1, $data['totals']['orders'], 'the edge order is outside the store-tz window');

        Carbon::setTestNow();
    }

    /** P6-322: report revenue is the dashboard's frozen revenue, unchanged by a later menu price rise. */
    public function test_revenue_matches_dashboard_after_price_change(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => '1']);

        $this->orderAtBranch($r, $branch, $item, 2); // frozen grand_total 20

        // A later price change must not rewrite historical revenue.
        $item->refresh()->update(['price' => 99]);

        $since = now()->subDay();
        $row = BranchReport::row($r->id, $branch, $since);
        $dashboard = round(Order::revenueSum(
            Order::where('store_id', $r->id)
                ->where('branch_id', $branch->id)
                ->where('created_at', '>=', $since)
        ), 2);

        $this->assertSame($dashboard, $row->revenue, 'report revenue equals the dashboard revenueSum');
        $this->assertSame(20.0, $row->revenue, 'frozen grand_total survives the price rise');
    }

    /** P6-322: a cancelled order never wins the top-item badge, however large its quantity. */
    public function test_top_item_ignores_cancelled_orders(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $main = Branch::where('store_id', $r->id)->first();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $burger = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => '1']);
        $pizza = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 10, 'active' => '1']);

        $this->orderAtBranch($r, $main, $burger, 1);

        $cancelled = Order::create([
            'store_id' => $r->id, 'branch_id' => $main->id, 'type' => 'pickup',
            'customer_name' => 'X', 'status' => 'cancelled', 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $cancelled->id, 'item_id' => $pizza->id, 'quantity' => 100, 'variation' => 0]);
        $cancelled->load('items');
        $cancelled->snapshotTotals();

        $row = BranchReport::row($r->id, $main, now()->subDay());
        $this->assertSame('Burger', $row->top_item);
    }

    /** P6-322: an unpaid pending order is an order placed but contributes no revenue. */
    public function test_unpaid_pending_order_counts_in_orders_not_revenue(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $main = Branch::where('store_id', $r->id)->first();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => '1']);

        $this->orderAtBranch($r, $main, $item, 2); // completed, revenue 20

        $pending = Order::create([
            'store_id' => $r->id, 'branch_id' => $main->id, 'type' => 'pickup',
            'customer_name' => 'X', 'status' => 'new', 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $pending->id, 'item_id' => $item->id, 'quantity' => 5, 'variation' => 0]);
        $pending->load('items');
        $pending->snapshotTotals();

        $row = BranchReport::row($r->id, $main, now()->subDay());
        $this->assertSame(2, $row->orders, 'the unpaid pending order is an order placed');
        $this->assertSame(20.0, $row->revenue, 'the unpaid pending order adds no revenue');
    }
}
