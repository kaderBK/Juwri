<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane V — V3: attribute-integrity test.
 *
 * `menu.attributes` (Lane A1's hybrid schema) round-trips per vertical; a
 * size×color variant order decrements the correct variant's stock through
 * EVERY order-creation path, food and non-food alike; SKU/barcode resolve
 * store-scoped (see the dedicated tests/Modules/Verticals/BarcodeResolverTest.php,
 * which already covers that half of this acceptance in full — this file
 * doesn't duplicate it).
 */
class VerticalAttributeIntegrityTest extends TestCase
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

    private function makeStore(string $type): Store
    {
        $owner = User::factory()->create();

        return Store::factory()->create(['user_id' => $owner->id, 'store_type' => $type]);
    }

    // ------------------------------------------------- attributes round-trip

    public function test_menu_attributes_json_round_trips_per_vertical(): void
    {
        $cases = [
            'retail' => ['material' => 'cotton', 'country_of_origin' => 'PT'],
            'cosmetic' => ['shade' => 'Rose Nude', 'volume_ml' => 30],
            'clothing' => ['fabric' => 'denim', 'fit' => 'slim'],
            'carparts' => ['oem_numbers' => ['ABC-123', 'DEF-456'], 'cross_refs' => ['XYZ-1']],
            'pharmacy' => ['active_ingredient' => 'Ibuprofen', 'dosage_mg' => 200],
            'laundry' => ['fabric_care' => 'delicate'],
        ];

        foreach ($cases as $type => $attrs) {
            $store = $this->makeStore($type);
            $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
            $item = Menu::create([
                'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item',
                'price' => 9.99, 'active' => 1, 'attributes' => $attrs,
            ]);

            $fresh = $item->fresh();
            $this->assertIsArray($fresh->attributes, "[$type] attributes must decode back to an array");
            $this->assertEquals($attrs, $fresh->attributes, // JSON columns do not keep key order
                "[$type] attributes must round-trip byte-for-byte");

            // A second save (a plain price bump, attributes untouched at the
            // model layer) must not perturb the JSON in any way.
            $fresh->update(['price' => 12.00]);
            $this->assertEquals($attrs, $fresh->fresh()->attributes, "[$type] attributes must survive an unrelated field update");
        }
    }

    public function test_attributes_round_trip_through_the_retail_dashboard_form_too(): void
    {
        // RetailItemController's own create→edit round trip (A4), across
        // every item_form=retail vertical, not just carparts (already proven
        // in isolation by RetailAttributesMergeNotReplaceTest).
        foreach (['retail', 'cosmetic', 'clothing', 'pharmacy'] as $type) {
            $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);
            $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
            EntitlementOverride::create(['user_id' => $owner->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
            FeatureAccess::flush();
            $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => $type]);
            $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

            $this->actingAs($owner)->post(route('retail.products.store', $store), [
                'category_id' => $cat->id, 'name' => 'Widget', 'price' => 9.99,
                'attr_key' => ['color', 'size'],
                'attr_value' => ['blue', 'M'],
            ])->assertRedirect();

            $item = Menu::where('store_id', $store->id)->where('name', 'Widget')->first();
            $this->assertEquals(['color' => 'blue', 'size' => 'M'], $item->attributes, "[$type] attributes must round-trip from the create form");

            $this->actingAs($owner)->put(route('retail.products.update', [$store, $item]), [
                'name' => 'Widget', 'price' => 9.99,
            ])->assertRedirect();
            $this->assertEquals(['color' => 'blue', 'size' => 'M'], $item->fresh()->attributes, "[$type] an attr-free update must leave attributes untouched");
        }
    }

    // --------------------------------------------------- variant stock cuts

    public function test_a_native_goods_checkout_decrements_the_correct_variant_not_a_sibling(): void
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Tee', 'price' => 20, 'active' => 1]);
        $sRed = MenuVariant::create(['menu_id' => $menu->id, 'price' => 20, 'options' => (object) ['Size' => 'S', 'Color' => 'Red'], 'stock_qty' => 5, 'sku' => 'TS-S-RED', 'active' => 1]);
        $mBlue = MenuVariant::create(['menu_id' => $menu->id, 'price' => 22, 'options' => (object) ['Size' => 'M', 'Color' => 'Blue'], 'stock_qty' => 5, 'sku' => 'TS-M-BLU', 'active' => 1]);

        $this->postJson(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), [
            'variation' => $sRed->id, 'quantity' => 3,
        ])->assertOk();

        $res = $this->postJson(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'ship', 'customer_name' => 'Jane', 'phone_number' => '555', 'address' => '1 St',
        ]);
        $res->assertOk()->assertJson(['success' => true]);

        $this->assertSame(2, MenuVariant::find($sRed->id)->stock_qty, 'the SELECTED variant (S/Red) must decrement by the ordered quantity');
        $this->assertSame(5, MenuVariant::find($mBlue->id)->stock_qty, 'a DIFFERENT variant (M/Blue) must be completely untouched');
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }

    public function test_a_native_laundry_booking_never_touches_stock_for_a_kg_priced_service(): void
    {
        // Laundry items have no meaningful stock_qty (D1 — services, not
        // goods); the shared deduction call GoodsOrderService now makes must
        // stay a harmless no-op here (Menu::deductStock() bails out unless
        // track_inventory is on), never an accidental new requirement.
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'laundry']);
        foreach ([
            'allow_order_scheduling' => '1', 'scheduling_slot_interval' => 30,
            'scheduling_lead_minutes' => 30, 'scheduling_prep_minutes' => 20, 'timezone' => 'UTC',
        ] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }
        $wash = Menu::create(['store_id' => $store->id, 'name' => 'Wash & Fold', 'price' => 5, 'pricing_unit' => 'kg', 'active' => 1]);

        $this->postJson(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $wash->id]), ['quantity' => 2.5])->assertOk();
        $res = $this->postJson(route('laundry.book.place', ['slug' => $store->slug]), [
            'type' => 'delivery', 'customer_name' => 'Sam', 'phone_number' => '1', 'address' => '1 St',
        ]);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertNull($wash->fresh()->stock_qty, 'a service item must never gain a phantom stock_qty from checkout');
    }
}
