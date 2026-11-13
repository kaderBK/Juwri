<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Ordering\Models\Cart;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rev/multi-vertical B6 — cart qty + line semantics for goods.
 *
 * A qty stepper + variant select adds a cart line; the cart shows the
 * correct variant and line total (price × qty).
 */
class GoodsCartControllerTest extends TestCase
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

    private function retailStoreWithVariantItem(): array
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'T-Shirt', 'price' => 20, 'active' => 1]);
        $variantM = MenuVariant::create(['menu_id' => $menu->id, 'price' => 25, 'active' => 1]);
        $variantL = MenuVariant::create(['menu_id' => $menu->id, 'price' => 27, 'active' => 1]);

        return [$store, $menu, $variantM, $variantL];
    }

    public function test_product_page_shows_variant_options(): void
    {
        [$store, $menu] = $this->retailStoreWithVariantItem();

        $res = $this->get(route('storefront.goods.product', ['slug' => $store->slug, 'menu' => $menu->id]));

        $res->assertOk();
        $res->assertSeeHtml('name="variation"');
    }

    public function test_adding_a_variant_and_qty_creates_a_correct_cart_line(): void
    {
        [$store, $menu, $variantM] = $this->retailStoreWithVariantItem();

        $res = $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), [
            'variation' => $variantM->id,
            'quantity' => 3,
        ]);
        $res->assertRedirect();

        $cartRes = $this->get(route('storefront.goods.cart.show', ['slug' => $store->slug]));
        $cartRes->assertOk();
        $cartRes->assertSee('T-Shirt');
        // 3 × 25.00 = 75.00
        $cartRes->assertSee('75.00');
    }

    public function test_adding_the_same_variant_twice_increments_the_existing_line(): void
    {
        [$store, $menu, $variantM] = $this->retailStoreWithVariantItem();

        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), [
            'variation' => $variantM->id, 'quantity' => 2,
        ]);
        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), [
            'variation' => $variantM->id, 'quantity' => 1,
        ]);

        $cartRes = $this->get(route('storefront.goods.cart.show', ['slug' => $store->slug]));
        // one merged line of qty 3, not two separate lines
        $cartRes->assertSee('75.00');
    }

    public function test_different_variants_of_the_same_item_are_separate_lines(): void
    {
        [$store, $menu, $variantM, $variantL] = $this->retailStoreWithVariantItem();

        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), [
            'variation' => $variantM->id, 'quantity' => 1,
        ]);
        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), [
            'variation' => $variantL->id, 'quantity' => 1,
        ]);

        $cartRes = $this->get(route('storefront.goods.cart.show', ['slug' => $store->slug]));
        $cartRes->assertSee('25.00');
        $cartRes->assertSee('27.00');
    }

    public function test_a_variant_from_another_store_is_rejected(): void
    {
        [$storeA, $menuA] = $this->retailStoreWithVariantItem();
        [$storeB, , $variantFromB] = $this->retailStoreWithVariantItem();

        $res = $this->post(route('storefront.goods.cart.add', ['slug' => $storeA->slug, 'menu' => $menuA->id]), [
            'variation' => $variantFromB->id,
            'quantity' => 1,
        ]);

        $res->assertSessionHasErrors('variation');
    }

    /** A food-style item with add-ons (MenuExtra) — the aurelia dining case. */
    private function storeWithAddonItem(): array
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'hotel']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Club Sandwich', 'price' => 40, 'active' => 1]);
        $fries = MenuExtra::create(['menu_id' => $menu->id, 'title' => 'Fries', 'price' => 8, 'active' => 1]);
        $drink = MenuExtra::create(['menu_id' => $menu->id, 'title' => 'Soft Drink', 'price' => 6, 'active' => 1]);

        return [$store, $menu, $fries, $drink];
    }

    public function test_adding_addons_stores_them_and_bumps_the_line_total(): void
    {
        [$store, $menu, $fries, $drink] = $this->storeWithAddonItem();

        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), [
            'quantity' => 2,
            'extras' => [$fries->id, $drink->id],
        ])->assertRedirect();

        $cartRes = $this->get(route('storefront.goods.cart.show', ['slug' => $store->slug]));
        $cartRes->assertOk();
        // 2 × (40 + 8 + 6) = 108.00
        $cartRes->assertSee('108.00');
    }

    public function test_same_item_different_addons_are_separate_lines(): void
    {
        [$store, $menu, $fries, $drink] = $this->storeWithAddonItem();

        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), [
            'quantity' => 1, 'extras' => [$fries->id],
        ]);
        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), [
            'quantity' => 1, 'extras' => [$drink->id],
        ]);

        $this->assertSame(2, Cart::where('store_id', $store->id)->first()->lines()->count());
    }

    public function test_an_addon_from_another_item_is_rejected(): void
    {
        [$store, $menu] = $this->storeWithAddonItem();
        $other = Menu::create(['store_id' => $store->id, 'name' => 'Salad', 'price' => 30, 'active' => 1]);
        $foreignExtra = MenuExtra::create(['menu_id' => $other->id, 'title' => 'Nuts', 'price' => 5, 'active' => 1]);

        $res = $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), [
            'quantity' => 1, 'extras' => [$foreignExtra->id],
        ]);

        $res->assertSessionHasErrors('extras');
    }
}
