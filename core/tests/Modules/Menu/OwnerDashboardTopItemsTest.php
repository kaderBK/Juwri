<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Juwri\Core\Models\Language;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * R7 — top-selling items card (qty + revenue) for the selected window.
 */
class OwnerDashboardTopItemsTest extends TestCase
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

    private function makeMenu(int $storeId, string $name, float $price): Menu
    {
        return Menu::create([
            'store_id' => $storeId, 'name' => $name, 'price' => $price,
            'description' => 'T', 'active' => 1, 'position' => 1,
        ]);
    }

    private function orderWithItems(int $storeId, array $items): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'store_id' => $storeId, 'status' => 'completed', 'type' => 'dine_in',
            'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now(),
        ]);
        foreach ($items as $menuId => $qty) {
            DB::table('order_items')->insert([
                'order_id' => $orderId, 'item_id' => $menuId, 'quantity' => $qty,
                'variation' => 0, 'status' => 'pending',
            ]);
        }
    }

    public function test_top_items_ranked_by_quantity_with_revenue(): void
    {
        [$owner, $store] = $this->makeOwnerWithStore();
        $burger = $this->makeMenu($store->id, 'Burger', 10);
        $fries = $this->makeMenu($store->id, 'Fries', 5);
        // Burger x2 (rev 20), Fries x5 (rev 25).
        $this->orderWithItems($store->id, [$burger->id => 2, $fries->id => 5]);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('Top items');
        $response->assertSeeHtml('topitems');

        $top = $response->viewData('topItems');
        $this->assertSame('Fries', $top[0]['name'], 'Fries ranks first by quantity');
        $this->assertSame(5, $top[0]['qty']);
        $this->assertSame(25.0, (float) $top[0]['revenue']);
        $this->assertSame('Burger', $top[1]['name']);
        $this->assertSame(2, $top[1]['qty']);
        $this->assertSame(20.0, (float) $top[1]['revenue']);
    }

    /** Non-revenue (cancelled/unpaid) orders don't inflate top-item qty or revenue. */
    public function test_top_items_exclude_non_revenue_orders(): void
    {
        [$owner, $store] = $this->makeOwnerWithStore();
        $burger = $this->makeMenu($store->id, 'Burger', 10);
        $this->orderWithItems($store->id, [$burger->id => 2]); // completed → counts (20)

        $cancelledId = DB::table('orders')->insertGetId([
            'store_id' => $store->id, 'status' => 'cancelled', 'type' => 'dine_in',
            'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $cancelledId, 'item_id' => $burger->id, 'quantity' => 9, 'variation' => 0, 'status' => 'pending',
        ]);

        $top = $this->actingAs($owner)->get(route('dashboard'))->viewData('topItems');
        $this->assertCount(1, $top);
        $this->assertSame(2, $top[0]['qty'], 'cancelled order qty excluded');
        $this->assertSame(20.0, (float) $top[0]['revenue'], 'cancelled order revenue excluded');
    }

    /** Revenue uses the line's frozen unit_price, not the current menu price. */
    public function test_top_items_revenue_uses_snapshot_unit_price(): void
    {
        [$owner, $store] = $this->makeOwnerWithStore();
        $burger = $this->makeMenu($store->id, 'Burger', 10); // live price 10
        $orderId = DB::table('orders')->insertGetId([
            'store_id' => $store->id, 'status' => 'completed', 'type' => 'dine_in',
            'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => $burger->id, 'quantity' => 2, 'variation' => 0,
            'unit_price_minor' => 700, 'status' => 'pending', // P1-05: 7.00 USD in minor units
            // frozen (e.g. happy hour) below the live 10
        ]);

        $top = $this->actingAs($owner)->get(route('dashboard'))->viewData('topItems');
        $this->assertSame(14.0, (float) $top[0]['revenue'], 'uses snapshot unit_price 7×2, not live 10');
    }

    public function test_top_items_empty_state(): void
    {
        [$owner] = $this->makeOwnerWithStore();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('No orders yet');
    }
}
