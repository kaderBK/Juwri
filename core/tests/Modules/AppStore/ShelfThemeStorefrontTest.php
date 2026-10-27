<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane C — C1/C2: the `shelf` grid-retail storefront theme.
 *
 * Renders a real store through the SAME public controller every other theme
 * uses (User\StoreController::publicView) and asserts on the rendered
 * DOM/text — never blade-direct. Mirrors SparseOptionsRenderTest's
 * makeOwnerAndRestaurant() fixture shape.
 */
class ShelfThemeStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
    }

    private function makeStore(string $storeType, string $template): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4),
            'description' => 'T',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'Store '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id,
            'store_type' => $storeType,
            'slug' => Str::slug($title).'-'.Str::random(5),
            'color' => '#1f6feb',
            'title' => $title,
            'sub_title' => 'T',
            'description' => 'T',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '1234',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return $store;
    }

    public function test_renders_the_product_grid_with_goods_vocabulary_not_food_words(): void
    {
        $store = $this->makeStore('retail', 'shelf');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Skincare']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Face Cream', 'price' => 24.50, 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml('id="shelf"');
        $response->assertSee('Face Cream');
        $response->assertSee('Skincare');
        // C5 vocab guard: goods wording present, food wording absent.
        $response->assertSeeHtml('Add to cart');
        $response->assertDontSeeHtml('>Dish<');
        $response->assertDontSeeHtml('>Dishes<');
        $response->assertDontSeeHtml('Add to order');
        $response->assertDontSeeHtml('Order food');
    }

    public function test_no_dish_framing_and_category_nav_present(): void
    {
        $store = $this->makeStore('cosmetic', 'shelf');
        $catA = MenuCategory::create(['store_id' => $store->id, 'name' => 'Lips']);
        $catB = MenuCategory::create(['store_id' => $store->id, 'name' => 'Eyes']);
        Menu::create(['store_id' => $store->id, 'category_id' => $catA->id, 'name' => 'Matte Lipstick', 'price' => 15, 'active' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $catB->id, 'name' => 'Mascara', 'price' => 18, 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml('data-cat="'.$catA->id.'"');
        $response->assertSeeHtml('data-cat="'.$catB->id.'"');
        $response->assertSee('Matte Lipstick');
        $response->assertSee('Mascara');
    }

    public function test_imageless_product_shows_a_neutral_placeholder_not_the_food_default(): void
    {
        // Follow-up fix: an item with no real image must NOT render the food-themed
        // storage/menu/default.png (a fork-and-plate glyph) on a retail card.
        $store = $this->makeStore('retail', 'shelf');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cables']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'USB-C Cable', 'price' => 12.50, 'image' => 'default.png', 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSee('USB-C Cable');
        $response->assertSeeHtml('sh-card__img--ph');          // neutral placeholder rendered
        $response->assertDontSeeHtml('storage/menu/default.png'); // no food default image
    }

    public function test_sold_out_item_is_flagged_and_add_button_disabled(): void
    {
        $store = $this->makeStore('retail', 'shelf');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Skincare']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Sold Out Serum', 'price' => 10, 'active' => 1, 'is_sold_out' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        // 'Sold out' already has a committed lang key ('sold_out' => 'Sold Out').
        $response->assertSee('Sold Out');
        $response->assertSeeHtml('disabled');
    }

    public function test_renders_rtl_for_arabic_locale(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('ar');

        $store = $this->makeStore('retail', 'shelf');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Skincare']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Face Cream', 'price' => 24.50, 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml('dir="rtl"');
    }

    public function test_dark_mode_toggle_is_wired(): void
    {
        $store = $this->makeStore('retail', 'shelf');

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml('shDarkToggle');
        $response->assertSeeHtml('prefers-color-scheme: dark');
    }

    public function test_variant_price_updates_are_wired_for_a_multi_variant_item(): void
    {
        $store = $this->makeStore('clothing', 'shelf');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Shirts']);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Tee', 'price' => 20, 'active' => 1]);
        $group = MenuVariantOption::create(['menu_id' => $menu->id, 'title' => 'Size', 'options' => ['S', 'M', 'L'], 'active' => 1]);
        MenuVariant::create(['menu_id' => $menu->id, 'price' => 20, 'options' => [$group->id => 0], 'active' => 1]);
        MenuVariant::create(['menu_id' => $menu->id, 'price' => 22, 'options' => [$group->id => 1], 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml('shVariantGroups');
        $response->assertSeeHtml('"variants":[');
    }

    public function test_restaurant_theme_rendering_is_unaffected(): void
    {
        $store = $this->makeStore('restaurant', 'crave');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12, 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertDontSeeHtml('id="shelf"');
        $response->assertSeeHtml('id="crave"');
    }
}
