<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Models\Cart;
use Tests\TestCase;

/**
 * Found by the e2eA retail journey: only the theme hid the buy button of a sold-out product, a switched-off product or
 * a variant without stock — the cart and the checkout accepted them, so a stale page or a hand-made request oversold
 * the shelf (stock went negative). The goods cart now refuses what cannot be sold, at add-to-cart, on a quantity
 * change and again at checkout (a product that sold out while it sat in the cart).
 */
class GoodsAvailabilityTest extends TestCase
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

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'clothing']);
    }

    private function add(Store $store, Menu $menu, array $data)
    {
        return $this->postJson(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), $data);
    }

    public function test_a_variant_with_no_stock_or_switched_off_cannot_be_added(): void
    {
        $store = $this->store();
        $tee = Menu::create(['store_id' => $store->id, 'name' => 'Basic tee', 'price' => 20, 'active' => 1]);
        $sold = MenuVariant::create(['menu_id' => $tee->id, 'price' => 20, 'active' => 1, 'stock_qty' => 0, 'options' => (object) ['Size' => 'S']]);
        $few = MenuVariant::create(['menu_id' => $tee->id, 'price' => 20, 'active' => 1, 'stock_qty' => 2, 'options' => (object) ['Size' => 'M']]);
        $off = MenuVariant::create(['menu_id' => $tee->id, 'price' => 20, 'active' => 0, 'stock_qty' => 9, 'options' => (object) ['Size' => 'L']]);
        $untracked = MenuVariant::create(['menu_id' => $tee->id, 'price' => 20, 'active' => 1, 'stock_qty' => null, 'options' => (object) ['Size' => 'XL']]);

        $this->add($store, $tee, ['variation' => $sold->id, 'quantity' => 1])->assertStatus(422)->assertJsonPath('message', 'Basic tee is sold out.');
        $this->add($store, $tee, ['variation' => $off->id, 'quantity' => 1])->assertStatus(422)->assertJsonPath('message', '"Basic tee" is no longer available.');

        $this->add($store, $tee, ['variation' => $few->id, 'quantity' => 3])->assertStatus(422)->assertJsonPath('message', 'Only 2 of "Basic tee" left.');
        $this->add($store, $tee, ['variation' => $few->id, 'quantity' => 2])->assertOk();
        // the cart already holds the 2: one more is above the stock
        $this->add($store, $tee, ['variation' => $few->id, 'quantity' => 1])->assertStatus(422);

        // a variant that does not track stock is never refused for stock
        $this->add($store, $tee, ['variation' => $untracked->id, 'quantity' => 50])->assertOk();
    }

    public function test_a_sold_out_off_or_depleted_product_cannot_be_added_and_a_stocked_one_can(): void
    {
        $store = $this->store();
        $sold = Menu::create(['store_id' => $store->id, 'name' => 'Cap', 'price' => 9, 'active' => 1, 'is_sold_out' => true]);
        $off = Menu::create(['store_id' => $store->id, 'name' => 'Scarf', 'price' => 9, 'active' => 0]);
        $tracked = Menu::create(['store_id' => $store->id, 'name' => 'Socks', 'price' => 4, 'active' => 1, 'track_inventory' => 1, 'stock_qty' => 3]);
        $untracked = Menu::create(['store_id' => $store->id, 'name' => 'Belt', 'price' => 4, 'active' => 1, 'track_inventory' => 0, 'stock_qty' => 1]);

        $this->add($store, $sold, ['quantity' => 1])->assertStatus(422)->assertJsonPath('message', 'Cap is sold out.');
        $this->add($store, $off, ['quantity' => 1])->assertStatus(422);
        $this->add($store, $tracked, ['quantity' => 4])->assertStatus(422)->assertJsonPath('message', 'Only 3 of "Socks" left.');
        $this->add($store, $tracked, ['quantity' => 3])->assertOk();
        $this->add($store, $untracked, ['quantity' => 5])->assertOk(); // not tracked → stock is not a limit
    }

    public function test_checkout_refuses_a_cart_whose_product_sold_out_after_it_was_added(): void
    {
        $store = $this->store();
        $tee = Menu::create(['store_id' => $store->id, 'name' => 'Basic tee', 'price' => 20, 'active' => 1]);
        $variant = MenuVariant::create(['menu_id' => $tee->id, 'price' => 20, 'active' => 1, 'stock_qty' => 2, 'options' => (object) ['Size' => 'M']]);
        $this->add($store, $tee, ['variation' => $variant->id, 'quantity' => 2])->assertOk();

        // someone else buys the stock first
        $variant->update(['stock_qty' => 0]);

        $this->postJson(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'in_store', 'customer_name' => 'A', 'phone_number' => '+966500000000',
        ])->assertStatus(422)->assertJsonPath('message', 'Basic tee is sold out.');
        $this->assertSame(0, \App\Models\Order::where('store_id', $store->id)->count(), 'no order may be placed for stock that is gone');
        $this->assertSame(0, (int) $variant->fresh()->stock_qty, 'stock must not go negative');
        $this->assertSame(1, Cart::where('store_id', $store->id)->count());
    }
}
