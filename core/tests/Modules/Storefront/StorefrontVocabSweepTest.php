<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane C — C5: vocab sweep of shared storefront surfaces.
 *
 * Grep-guard, run against the ACTUAL RENDERED HTML (through the controller,
 * never blade-direct) rather than raw template source — the acceptance is
 * about what a shopper on a retail/service storefront SEES, and rendering is
 * also the only way to tell an explanatory code comment ("never hardcode
 * Dish…") apart from a real leak.
 */
class StorefrontVocabSweepTest extends TestCase
{
    use RefreshDatabase;

    /** Words that must never appear on a non-food storefront. */
    private const FORBIDDEN = ['>Dish<', '>Dishes<', 'Add to order', 'Order food', '>Kitchen<'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        // restaurant.infoPage carries the Installed gate (publicView does not).
        $this->withoutMiddleware([Installed::class]);
    }

    private function makeStore(string $storeType, string $template): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'Store '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'store_type' => $storeType,
            'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return $store;
    }

    public function test_shelf_storefront_carries_no_food_vocabulary(): void
    {
        $store = $this->makeStore('retail', 'shelf');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Snacks']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Chips', 'price' => 3, 'active' => 1]);

        $html = $this->get(route('publicView', $store->slug))->getContent();

        foreach (self::FORBIDDEN as $needle) {
            $this->assertStringNotContainsString($needle, $html, "shelf storefront leaked food wording: {$needle}");
        }
    }

    public function test_bookla_storefront_carries_no_food_vocabulary(): void
    {
        $store = $this->makeStore('laundry', 'bookla');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Wash']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Wash & Fold', 'price' => 5, 'active' => 1]);

        $html = $this->get(route('publicView', $store->slug))->getContent();

        foreach (self::FORBIDDEN as $needle) {
            $this->assertStringNotContainsString($needle, $html, "bookla storefront leaked food wording: {$needle}");
        }
    }

    /**
     * The one TRULY shared, cross-theme view (store_templates/info-page.blade.php
     * — every theme's owner-authored Terms/Privacy page extends the same shared
     * layout, unlike the per-theme index pages). A restaurant must see the
     * EXACT original string (byte-identical — "restaurants unaffected");
     * a non-food store must never see "menu" in its back-link.
     */
    public function test_info_page_back_link_is_vocab_aware_and_restaurant_is_byte_identical(): void
    {
        $retail = $this->makeStore('retail', 'shelf');
        StoreOption::updateStoreOption($retail->id, 'sf_page_terms', 'Some terms.');
        $retailHtml = $this->get(route('store.infoPage', [$retail->id, 'terms']))->getContent();
        $this->assertStringNotContainsString('Back to Menu', $retailHtml);
        $this->assertStringNotContainsString('Back to menu', $retailHtml);
        $this->assertStringContainsString('Back to', $retailHtml);
        $this->assertStringContainsString('Catalog', $retailHtml);

        // 'back_to_menu' already has a committed lang key ('Back to Menu',
        // capitalised) — this is the pre-existing legacy string, unchanged.
        $store = $this->makeStore('restaurant', 'crave');
        StoreOption::updateStoreOption($store->id, 'sf_page_terms', 'Some terms.');
        $restaurantHtml = $this->get(route('store.infoPage', [$store->id, 'terms']))->getContent();
        $this->assertStringContainsString('Back to Menu', $restaurantHtml, 'a restaurant info page must render the exact legacy string, byte-identical');
    }
}
