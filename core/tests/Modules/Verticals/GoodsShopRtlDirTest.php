<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P6-227 — the goods shop pages (cart, product, checkout, size guide) took
 * their `dir` from `config('laravellocalization.rtlLocales')`, a key that does
 * not exist, so Arabic always rendered `dir="ltr"`. They now use is_rtl() like
 * order-hub/layout.blade.php.
 */
class GoodsShopRtlDirTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function goodsStore(): array
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'T-Shirt', 'price' => 20, 'active' => 1]);

        return [$store, $menu];
    }

    public function test_goods_pages_render_dir_rtl_for_arabic(): void
    {
        [$store, $menu] = $this->goodsStore();

        app()->setLocale('ar');

        $this->get(route('storefront.goods.cart.show', ['slug' => $store->slug]))
            ->assertOk()
            ->assertSee('dir="rtl"', false);

        $this->get(route('storefront.goods.product', ['slug' => $store->slug, 'menu' => $menu->id]))
            ->assertOk()
            ->assertSee('dir="rtl"', false);

        $this->get(route('storefront.checkout.show', ['slug' => $store->slug]))
            ->assertOk()
            ->assertSee('dir="rtl"', false);

        $guide = [
            'id' => 'sg-1',
            'title' => 'Shirts',
            'unit' => 'cm',
            'columns' => ['Size', 'Chest'],
            'rows' => [['S', '90']],
            'note' => null,
        ];
        $html = view('shop.goods.size-guide', ['store' => $store, 'guide' => $guide])->render();

        $this->assertStringContainsString('dir="rtl"', $html);
    }

    public function test_goods_pages_stay_ltr_for_english(): void
    {
        [$store, $menu] = $this->goodsStore();

        app()->setLocale('en');

        $this->get(route('storefront.goods.cart.show', ['slug' => $store->slug]))
            ->assertOk()
            ->assertSee('dir="ltr"', false);

        $this->get(route('storefront.goods.product', ['slug' => $store->slug, 'menu' => $menu->id]))
            ->assertOk()
            ->assertSee('dir="ltr"', false);
    }
}
