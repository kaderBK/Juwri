<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D8 — command-palette deep links. The palette threw away the entity id and
 * dropped the user on the unfiltered list; now order hits carry ?order=<id> and
 * menu hits carry ?item=<id> so the target page focuses the specific record.
 */
class DashboardSearchDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    public function test_order_and_menu_results_carry_focus_query_params(): void
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pepperoni Pizza', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'takeaway', 'customer_name' => 'Zorro',
            'phone_number' => '555-77', 'status' => 'pending', 'seen' => 0, 'created_at' => now(),
        ]);

        // Order hit → ?order=<id>.
        $json = $this->actingAs($owner)->getJson(route('dashboard.search', ['q' => 'Zorro']))->assertOk()->json();
        $orderGroup = collect($json['groups'])->firstWhere('key', 'orders');
        $this->assertNotNull($orderGroup);
        $this->assertStringContainsString('?order=' . $order->id, $orderGroup['items'][0]['url']);

        // Menu hit → ?item=<id>.
        $json = $this->actingAs($owner)->getJson(route('dashboard.search', ['q' => 'Pepperoni']))->assertOk()->json();
        $itemGroup = collect($json['groups'])->firstWhere('key', 'items');
        $this->assertNotNull($itemGroup);
        $this->assertStringContainsString('?item=' . $menu->id, $itemGroup['items'][0]['url']);
    }
}
