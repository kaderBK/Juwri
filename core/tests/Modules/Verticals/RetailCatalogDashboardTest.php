<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Brand;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane A3/A4/A5 — the retail catalog dashboard.
 *
 * Brand CRUD (A3), the retail item create/edit form with NO food fields (A4),
 * and the vocab-relabeled catalog list + category management (A5). A brand-new
 * surface (RetailItemController/BrandController/RetailCategoryController),
 * never the shared food item form — a restaurant store 404s on every route
 * here, and a retail item's food column stays the harmless F0-4 default.
 */
class RetailCatalogDashboardTest extends TestCase
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

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999], 'status' => 1]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        // rev/multi-vertical Lane V (V4): these manage surfaces now require
        // the multi_vertical entitlement — every fixture here represents an
        // ALREADY-entitled owner (V4's own gate test covers the unentitled
        // case directly), so grant it once, centrally.
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();

        return $user;
    }

    private function makeStore(User $owner, string $type = 'retail'): Store
    {
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ── A3: Brands ──────────────────────────────────────────────────────────

    public function test_owner_can_create_a_brand_and_assign_it_to_an_item(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'cosmetic');

        $this->actingAs($owner)->post(route('retail.brands.store', $store), ['name' => 'Glow Co'])->assertRedirect();
        $brand = Brand::where('store_id', $store->id)->where('name', 'Glow Co')->first();
        $this->assertNotNull($brand);

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Skincare']);
        $this->actingAs($owner)->post(route('retail.products.store', $store), [
            'category_id' => $cat->id, 'brand_id' => $brand->id, 'name' => 'Face Cream', 'price' => 15,
        ])->assertRedirect();

        $item = Menu::where('store_id', $store->id)->where('name', 'Face Cream')->first();
        $this->assertNotNull($item);
        $this->assertSame($brand->id, $item->brand_id);

        // Filter catalog by brand.
        $resp = $this->actingAs($owner)->get(route('retail.products.index', ['store' => $store->id, 'brand_id' => $brand->id]));
        $resp->assertOk()->assertSee('Face Cream');
    }

    public function test_a_restaurant_store_cannot_reach_the_brand_pages(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'restaurant');

        $this->actingAs($owner)->get(route('retail.brands.index', $store))->assertNotFound();
        $this->actingAs($owner)->get(route('retail.products.index', $store))->assertNotFound();
    }

    // ── A4: retail item form (no food fields) ──────────────────────────────

    public function test_create_and_edit_a_cosmetic_item_end_to_end(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'cosmetic');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Skincare']);

        $this->actingAs($owner)->get(route('retail.products.create', $store))->assertOk()->assertDontSee('is_vegan');

        $this->actingAs($owner)->post(route('retail.products.store', $store), [
            'category_id' => $cat->id,
            'name' => 'Lip Balm',
            'price' => 6.5,
            'weight' => 0.05,
            'dimensions_length' => 5, 'dimensions_width' => 2, 'dimensions_height' => 2, 'dimensions_unit' => 'cm',
            'attr_key' => ['scent'], 'attr_value' => ['mint'],
            'barcode' => '111222333',
            'active' => 1,
        ])->assertRedirect();

        $item = Menu::where('store_id', $store->id)->where('name', 'Lip Balm')->first();
        $this->assertNotNull($item);
        $this->assertSame(['scent' => 'mint'], $item->attributes);
        $this->assertSame(['length' => 5, 'width' => 2, 'height' => 2, 'unit' => 'cm'], $item->dimensions);
        $this->assertSame('111222333', $item->barcode);
        $this->assertSame('111222333', $item->plu, 'barcode is an ALIAS onto plu (A6), not a duplicate column');

        $this->actingAs($owner)->get(route('retail.products.edit', [$store, $item]))->assertOk()->assertSee('Lip Balm');

        $this->actingAs($owner)->put(route('retail.products.update', [$store, $item]), [
            'name' => 'Lip Balm Deluxe', 'price' => 8, 'category_id' => $cat->id,
        ])->assertRedirect();

        $this->assertSame('Lip Balm Deluxe', $item->fresh()->getRawOriginal('name'));
    }

    /**
     * Found by the e2eA retail journey: price / cost / weight were plain number inputs (step 1), so the browser
     * refused 4.50 and 0.250 kg — an owner could only enter whole numbers. The form must allow two decimals for
     * money and three for weight.
     */
    public function test_money_and_weight_fields_accept_decimals_in_the_browser(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'retail');

        $html = $this->actingAs($owner)->get(route('retail.products.create', $store))->assertOk()->getContent();

        foreach (['price' => '0.01', 'cost' => '0.01', 'weight' => '0.001'] as $name => $step) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*name="'.$name.'"[^>]*step="'.preg_quote($step, '/').'"|<input[^>]*step="'.preg_quote($step, '/').'"[^>]*name="'.$name.'"/',
                $html,
                "the {$name} input must allow step {$step}"
            );
        }
    }

    /**
     * Found by the e2eA laundry journey: the item forms passed `value="{{ … }}"` to <x-ui.field>, which escapes it
     * again, so "Tom & Jerry" reached the browser as `&amp;amp;` — and saving any edit stored the mangled text.
     * The edit form must hand the browser the name exactly once-escaped.
     */
    public function test_the_edit_form_does_not_double_escape_special_characters(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'retail');
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Tom & Jerry\'s "Mix" <b>', 'price' => 5, 'active' => 1, 'description' => 'Salt & pepper']);

        $html = $this->actingAs($owner)->get(route('retail.products.edit', [$store, $item]))->assertOk()->getContent();

        $this->assertStringContainsString('value="Tom &amp; Jerry&#039;s &quot;Mix&quot; &lt;b&gt;"', $html);
        $this->assertStringContainsString('>Salt &amp; pepper</textarea>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);

        // a plain save (price only) keeps the name exactly as it was
        $this->actingAs($owner)->put(route('retail.products.update', [$store, $item]), ['name' => 'Tom & Jerry\'s "Mix" <b>', 'price' => 6])->assertRedirect();
        $this->assertSame('Tom & Jerry\'s "Mix" <b>', $item->fresh()->getRawOriginal('name'));
    }

    /**
     * Found by the e2eA retail journey: the last unit sells a tracked product out, but restocking it in the product
     * form kept it sold out. Stock that ran out and is raised above zero lifts the flag; an owner's manual sold-out on
     * a product that still has stock is not touched.
     */
    public function test_restocking_a_product_that_ran_out_puts_it_back_on_sale(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'retail');
        $ranOut = Menu::create(['store_id' => $store->id, 'name' => 'Lamp', 'price' => 5, 'active' => 1, 'track_inventory' => 1, 'stock_qty' => 0, 'is_sold_out' => true]);
        $manual = Menu::create(['store_id' => $store->id, 'name' => 'Vase', 'price' => 5, 'active' => 1, 'track_inventory' => 1, 'stock_qty' => 4, 'is_sold_out' => true]);

        $this->actingAs($owner)->put(route('retail.products.update', [$store, $ranOut]), ['name' => 'Lamp', 'price' => 5, 'track_inventory' => 1, 'stock_qty' => 3, 'active' => 1])->assertRedirect();
        $this->actingAs($owner)->put(route('retail.products.update', [$store, $manual]), ['name' => 'Vase', 'price' => 5, 'track_inventory' => 1, 'stock_qty' => 6, 'active' => 1])->assertRedirect();

        $this->assertFalse((bool) $ranOut->fresh()->is_sold_out, 'restocked product must be back on sale');
        $this->assertSame(3, (int) $ranOut->fresh()->stock_qty);
        $this->assertTrue((bool) $manual->fresh()->is_sold_out, "the owner's manual sold-out must stay");
    }

    public function test_a_clothing_item_can_be_created_with_a_variant_matrix(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'clothing');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Shirts']);

        $this->actingAs($owner)->post(route('retail.products.store', $store), [
            'category_id' => $cat->id, 'name' => 'T-Shirt', 'price' => 25,
        ])->assertRedirect();
        $item = Menu::where('store_id', $store->id)->where('name', 'T-Shirt')->first();

        $this->actingAs($owner)->post(route('retail.products.variants.generate', [$store, $item]), [
            'axis_name' => ['Size', 'Color'],
            'axis_values' => ['S,M,L', 'Red,Blue,Green'],
        ])->assertRedirect();

        $this->assertSame(9, $item->variants()->count());

        $variant = $item->variants()->first();
        $this->actingAs($owner)->post(route('retail.products.variants.bulkUpdate', [$store, $item]), [
            'variants' => [$variant->id => ['price' => 30, 'sku' => 'TS-1', 'stock_qty' => 10, 'barcode' => 'BC1', 'active' => '1']],
        ])->assertRedirect();

        $variant->refresh();
        $this->assertSame('TS-1', $variant->sku);
        $this->assertSame(10, $variant->stock_qty);
    }

    // ── A5: catalog list + categories (vocab relabel) ───────────────────────

    public function test_retail_store_shows_vocab_words_and_legacy_menu_is_unaffected(): void
    {
        $owner = $this->makeUser();
        $retail = $this->makeStore($owner, 'retail');
        $store = $this->makeStore($owner, 'restaurant');

        $resp = $this->actingAs($owner)->get(route('retail.products.index', $retail));
        $resp->assertOk()->assertSee('Products')->assertSee('Categories');

        // Legacy food route is completely untouched by this lane.
        $this->actingAs($owner)->get(route('stores.menu', $store))->assertOk();
    }

    public function test_category_create_and_nesting(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'retail');

        $this->actingAs($owner)->post(route('retail.categories.store', $store), ['name' => 'Electronics'])->assertRedirect();
        $parent = MenuCategory::where('store_id', $store->id)->where('name', 'Electronics')->firstOrFail();

        $this->actingAs($owner)->post(route('retail.categories.store', $store), ['name' => 'Phones', 'parent' => $parent->id])->assertRedirect();
        $child = MenuCategory::where('store_id', $store->id)->where('name', 'Phones')->first();

        $this->assertNotNull($child);
        $this->assertSame($parent->id, $child->parent);

        $resp = $this->actingAs($owner)->get(route('retail.products.index', $store));
        $resp->assertOk()->assertSee('Electronics')->assertSee('Phones');
    }
}
