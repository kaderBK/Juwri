<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D1 — the orders screen replaces native alert()/confirm()/reload with toasts, a
 * styled confirm modal and in-place row updates. This asserts the shared toast +
 * confirm infra renders and the ajax payload the poll consumes returns rendered
 * order rows.
 */
class OrdersPageToastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $settings = new class(['currency_sign' => '$', 'currency_pos' => 1, 'currency_code' => 'USD', 'theme_color' => '#111', 'site_title' => 'X'])
        {
            public function __construct(private array $d) {}

            public function __get($k)
            {
                return $this->d[$k] ?? null;
            }

            public function __isset($k)
            {
                return isset($this->d[$k]);
            }
        };
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'admin_languages' => collect(),
        ]);
        $this->withoutMiddleware();
    }

    private function scaffold(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'takeaway', 'customer_name' => 'Jane',
            'phone_number' => '555', 'status' => 'pending', 'seen' => 0, 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);

        return [$owner, $r, $order];
    }

    public function test_orders_page_ships_the_toast_and_confirm_infra_no_native_alert(): void
    {
        [$owner, $r] = $this->scaffold();
        $html = $this->actingAs($owner)->get(route('stores.orders', $r->id))->assertOk()->getContent();

        $this->assertStringContainsString('dash-toast-host', $html);   // toast host
        $this->assertStringContainsString('dashConfirm', $html);      // styled confirm modal
        $this->assertStringContainsString('dashToast', $html);         // toast helper wired in
        // The delete button routes through the confirm modal, not native alert().
        $this->assertStringNotContainsString('window.alert(', $html);
    }

    public function test_ajax_payload_returns_rendered_order_rows_for_the_poll(): void
    {
        [$owner, $r, $order] = $this->scaffold();
        $json = $this->actingAs($owner)
            ->get(route('stores.orders', $r->id), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json();

        $this->assertTrue($json['success']);
        $this->assertNotEmpty($json['orders']);
        $this->assertStringContainsString('data-order-id="'.$order->id.'"', $json['orders'][0]);
    }
}
