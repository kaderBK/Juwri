<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use App\Models\Order;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rev/multi-vertical B2 — ship-to-address checkout.
 *
 * A retail store with `ship` enabled (B1) accepts a checkout that collects an
 * address, persists it on the order, and totals correctly (qty > 1) per the
 * theme-totals-contract (Order::computeMoney() derivation).
 */
class GoodsShipCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function retailStore(): Store
    {
        $owner = User::factory()->create();

        return Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);
    }

    public function test_checkout_page_offers_ship_when_enabled(): void
    {
        $store = $this->retailStore();
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Mug', 'price' => 15, 'active' => 1]);
        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), ['quantity' => 1]);

        $res = $this->get(route('storefront.checkout.show', ['slug' => $store->slug]));

        $res->assertOk();
        $res->assertSeeHtml('value="ship"');
    }

    public function test_placing_a_ship_order_persists_address_and_totals_correctly_with_qty_gt_1(): void
    {
        $store = $this->retailStore();
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Mug', 'price' => 15, 'active' => 1]);

        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), ['quantity' => 3]);

        $res = $this->post(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'ship',
            'address' => '221B Baker Street',
            'customer_name' => 'Jane',
            'phone_number' => '5551234',
        ]);

        $res->assertRedirect();

        $order = Order::where('store_id', $store->id)->first();
        $this->assertNotNull($order, 'no order was created');
        $this->assertSame('ship', $order->type);
        $this->assertSame('221B Baker Street', $order->address);
        $this->assertSame('new', $order->status);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(3, (int) $order->items()->first()->quantity);
        // 3 × 15 = 45, no tax/delivery configured for this store.
        $this->assertEqualsWithDelta(45.0, (float) $order->fresh(['items'])->total, 0.001);
    }

    public function test_addons_become_order_item_extras_and_count_toward_the_total(): void
    {
        $store = $this->retailStore();
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Combo', 'price' => 20, 'active' => 1]);
        $fries = MenuExtra::create(['menu_id' => $menu->id, 'title' => 'Fries', 'price' => 8, 'active' => 1]);

        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), [
            'quantity' => 2, 'extras' => [$fries->id],
        ]);

        $this->post(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'ship', 'address' => '1 St', 'customer_name' => 'Jo', 'phone_number' => '5551234',
        ])->assertRedirect();

        $order = Order::where('store_id', $store->id)->first();
        $item = $order->items()->first();
        $this->assertSame(1, OrderItemExtra::where('order_item_id', $item->id)->count());
        // 2 × (20 + 8) = 56.
        $this->assertEqualsWithDelta(56.0, (float) $order->fresh(['items'])->total, 0.001);
    }

    public function test_ship_without_an_address_is_rejected(): void
    {
        $store = $this->retailStore();
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Mug', 'price' => 15, 'active' => 1]);
        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), ['quantity' => 1]);

        $res = $this->post(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'ship',
        ]);

        $res->assertSessionHasErrors('address');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
    }

    public function test_a_mode_the_owner_disabled_in_b1_is_rejected_at_checkout(): void
    {
        $store = $this->retailStore();
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Mug', 'price' => 15, 'active' => 1]);
        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), ['quantity' => 1]);

        // Owner turns OFF everything except pickup_slot (B1).
        app(FulfillmentSettingsService::class)->update($store, ['pickup_slot']);

        $res = $this->post(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'ship',
            'address' => '1 St',
        ]);

        $res->assertSessionHasErrors('type');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
    }

    public function test_a_restaurant_cannot_place_a_ship_order(): void
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'restaurant']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Pizza', 'price' => 10, 'type' => 'veg', 'active' => 1]);
        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), ['quantity' => 1]);

        $res = $this->post(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'ship',
            'address' => '1 St',
        ]);

        $res->assertSessionHasErrors('type');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
    }
}
