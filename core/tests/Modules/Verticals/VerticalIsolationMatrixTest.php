<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Models\VehicleFitment;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Delivery\Models\Shipment;
use Juwri\Modules\Menu\Models\Brand;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane V — V1: vertical-isolation / IDOR matrix.
 *
 * A store of vertical X can never read or write another store's catalog,
 * items, variants, orders or fitments — no matter which of this pack's new
 * dashboard controllers is used — and a non-food vertical's own write
 * surfaces never accept food-only fields (G2/G3). One dynamic test iterates
 * every shipped vertical against its item-form surface (RetailItemController
 * for retail/cosmetic/clothing/carparts/pharmacy, ServiceItemController for
 * laundry), plus dedicated checks for the vertical-specific surfaces
 * (Brand/Category/Variant, Fitment, Pharmacy compliance, order boards).
 */
class VerticalIsolationMatrixTest extends TestCase
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

    /** @return array{0: User, 1: Store} */
    private function makeOwnerAndStore(string $type): array
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        // rev/multi-vertical Lane V (V4): these manage surfaces now require
        // the multi_vertical entitlement.
        EntitlementOverride::create(['user_id' => $owner->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();
        $title = 'S'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $store];
    }

    /** item_form === 'retail' verticals (VerticalRegistry — config/verticals.php). */
    private function retailShapedVerticals(): array
    {
        return ['retail', 'cosmetic', 'clothing', 'carparts', 'pharmacy'];
    }

    // ------------------------------------------------------------- catalog

    public function test_no_owner_can_read_or_write_another_owners_catalog_across_every_retail_shaped_vertical(): void
    {
        foreach ($this->retailShapedVerticals() as $type) {
            [$ownerA, $storeA] = $this->makeOwnerAndStore($type);
            [, $storeB] = $this->makeOwnerAndStore($type);

            $catB = MenuCategory::create(['store_id' => $storeB->id, 'name' => 'CatB']);
            $itemB = Menu::create(['store_id' => $storeB->id, 'category_id' => $catB->id, 'name' => 'ItemB', 'price' => 10, 'active' => 1]);
            $brandB = Brand::create(['store_id' => $storeB->id, 'name' => 'BrandB', 'slug' => 'brandb-'.Str::random(4), 'active' => 1]);
            $variantB = MenuVariant::create(['menu_id' => $itemB->id, 'price' => 12, 'active' => 1]);

            // RetailItemController — B's item, addressed through A's own store id.
            $this->actingAs($ownerA)->get(route('retail.products.edit', [$storeA, $itemB]))->assertNotFound("[$type] edit");
            $this->actingAs($ownerA)->put(route('retail.products.update', [$storeA, $itemB]), ['name' => 'Hacked', 'price' => 1])->assertNotFound("[$type] update");
            $this->actingAs($ownerA)->delete(route('retail.products.destroy', [$storeA, $itemB]))->assertNotFound("[$type] destroy");
            // ...and A can't even list/act on B's store outright.
            $this->actingAs($ownerA)->get(route('retail.products.index', $storeB))->assertNotFound("[$type] index on B's store");

            // BrandController.
            $this->actingAs($ownerA)->put(route('retail.brands.update', [$storeA, $brandB]), ['name' => 'Hacked'])->assertNotFound("[$type] brand update");
            $this->actingAs($ownerA)->delete(route('retail.brands.destroy', [$storeA, $brandB]))->assertNotFound("[$type] brand destroy");
            $this->actingAs($ownerA)->get(route('retail.brands.index', $storeB))->assertNotFound("[$type] brand index on B's store");

            // RetailCategoryController.
            $this->actingAs($ownerA)->put(route('retail.categories.update', [$storeA, $catB]), ['name' => 'Hacked'])->assertNotFound("[$type] category update");
            $this->actingAs($ownerA)->delete(route('retail.categories.destroy', [$storeA, $catB]))->assertNotFound("[$type] category destroy");

            // VariantMatrixController — both failure modes: B's item via A's
            // store, and B's variant attached to A's OWN item.
            $this->actingAs($ownerA)->post(route('retail.products.variants.bulkUpdate', [$storeA, $itemB]), [])->assertNotFound("[$type] variant bulkUpdate wrong store");
            $itemA = Menu::create(['store_id' => $storeA->id, 'category_id' => MenuCategory::create(['store_id' => $storeA->id, 'name' => 'CatA'])->id, 'name' => 'ItemA', 'price' => 5, 'active' => 1]);
            $this->actingAs($ownerA)->delete(route('retail.products.variants.destroy', [$storeA, $itemA, $variantB]))->assertNotFound("[$type] variant destroy wrong item");

            // Nothing above actually mutated B's data.
            $this->assertSame(10.0, (float) Menu::find($itemB->id)->price, "[$type] item price must be untouched");
            $this->assertSame('BrandB', Brand::find($brandB->id)->name, "[$type] brand name must be untouched");
            $this->assertSame('CatB', MenuCategory::find($catB->id)->name, "[$type] category name must be untouched");
            $this->assertNotNull(MenuVariant::find($variantB->id), "[$type] variant must not have been deleted");
        }
    }

    public function test_no_owner_can_read_or_write_another_owners_laundry_service_catalog(): void
    {
        [$ownerA, $storeA] = $this->makeOwnerAndStore('laundry');
        [, $storeB] = $this->makeOwnerAndStore('laundry');

        $catB = MenuCategory::create(['store_id' => $storeB->id, 'name' => 'Wash']);
        $serviceB = Menu::create(['store_id' => $storeB->id, 'category_id' => $catB->id, 'name' => 'Wash & Fold', 'price' => 5, 'pricing_unit' => 'kg', 'active' => 1]);

        $this->actingAs($ownerA)->get(route('laundry.services.edit', [$storeA, $serviceB]))->assertNotFound();
        $this->actingAs($ownerA)->put(route('laundry.services.update', [$storeA, $serviceB]), ['name' => 'Hacked', 'price' => 1])->assertNotFound();
        $this->actingAs($ownerA)->delete(route('laundry.services.destroy', [$storeA, $serviceB]))->assertNotFound();
        $this->actingAs($ownerA)->get(route('laundry.services.index', $storeB))->assertNotFound();

        $this->assertSame(5.0, (float) Menu::find($serviceB->id)->price);
    }

    // ------------------------------------------------------------- fitment

    public function test_no_owner_can_read_or_write_another_owners_vehicle_fitments(): void
    {
        [$ownerA, $storeA] = $this->makeOwnerAndStore('carparts');
        [, $storeB] = $this->makeOwnerAndStore('carparts');

        $catB = MenuCategory::create(['store_id' => $storeB->id, 'name' => 'Brakes']);
        $partB = Menu::create(['store_id' => $storeB->id, 'category_id' => $catB->id, 'name' => 'Pad Set', 'price' => 39, 'active' => 1]);
        $fitmentB = VehicleFitment::create(['menu_id' => $partB->id, 'store_id' => $storeB->id, 'year' => 2020, 'make' => 'Honda', 'model' => 'Civic']);

        $this->actingAs($ownerA)->get(route('retail.fitments.show', [$storeA, $partB]))->assertNotFound();
        $this->actingAs($ownerA)->post(route('retail.fitments.store', [$storeA, $partB]), ['year' => 2021, 'make' => 'Toyota', 'model' => 'Corolla'])->assertNotFound();
        $this->actingAs($ownerA)->post(route('retail.fitments.updateOem', [$storeA, $partB]), ['oem_numbers' => 'HACKED'])->assertNotFound();
        $this->actingAs($ownerA)->delete(route('retail.fitments.destroy', [$storeA, $partB, $fitmentB]))->assertNotFound();

        $this->assertNotNull(VehicleFitment::find($fitmentB->id), 'fitment must not have been deleted');
        $this->assertSame(0, VehicleFitment::where('menu_id', $partB->id)->where('make', 'Toyota')->count());
        $this->assertNull($partB->fresh()->attributes['oem_numbers'] ?? null);
    }

    // ------------------------------------------------------------ pharmacy

    public function test_no_owner_can_read_or_write_another_owners_pharmacy_compliance_fields(): void
    {
        [$ownerA, $storeA] = $this->makeOwnerAndStore('pharmacy');
        [, $storeB] = $this->makeOwnerAndStore('pharmacy');

        $catB = MenuCategory::create(['store_id' => $storeB->id, 'name' => 'OTC']);
        $itemB = Menu::create(['store_id' => $storeB->id, 'category_id' => $catB->id, 'name' => 'Pain Relief', 'price' => 8, 'active' => 1, 'age_restricted' => 0]);

        $this->actingAs($ownerA)->get(route('pharmacy.compliance.edit', [$storeA, $itemB]))->assertNotFound();
        $this->actingAs($ownerA)->put(route('pharmacy.compliance.update', [$storeA, $itemB]), ['age_restricted' => 1])->assertNotFound();

        $this->assertSame(0, (int) Menu::find($itemB->id)->age_restricted);
    }

    // --------------------------------------------------------------- orders

    public function test_no_owner_can_advance_another_owners_retail_or_laundry_order(): void
    {
        [$ownerA, $storeA] = $this->makeOwnerAndStore('retail');
        [, $storeB] = $this->makeOwnerAndStore('retail');
        $orderB = Order::create(['store_id' => $storeB->id, 'type' => 'ship', 'status' => 'new', 'track_token' => Str::lower(Str::random(32)), 'created_at' => now()]);

        // A's dashboard-current restaurant is storeA; B's order can never be "current" for A.
        $res = $this->actingAs($ownerA)->post(route('stores.orders.board.advance', $orderB).'?store_id='.$storeA->id);
        $res->assertNotFound();
        $this->assertSame('new', $orderB->fresh()->status);

        // B4 shipment controller — same tenancy trait, same order.
        $res = $this->actingAs($ownerA)->post(route('stores.orders.shipment.update', $orderB).'?store_id='.$storeA->id, ['tracking_number' => 'HACKED']);
        $res->assertNotFound();
        $this->assertNull(Shipment::where('order_id', $orderB->id)->first());

        [$laundryOwnerA, $laundryStoreA] = $this->makeOwnerAndStore('laundry');
        [, $laundryStoreB] = $this->makeOwnerAndStore('laundry');
        $laundryOrderB = Order::create(['store_id' => $laundryStoreB->id, 'type' => 'pickup_slot', 'status' => 'received', 'track_token' => Str::lower(Str::random(32)), 'created_at' => now()]);

        $res = $this->actingAs($laundryOwnerA)->post(route('stores.service_board.advance', $laundryOrderB).'?store_id='.$laundryStoreA->id);
        $res->assertNotFound();
        $this->assertSame('received', $laundryOrderB->fresh()->status);

        // The shared tenancy trait itself refuses even naming B's store as "current".
        $res = $this->actingAs($laundryOwnerA)->get(route('stores.service_board.index').'?store_id='.$laundryStoreB->id);
        $res->assertForbidden();
    }

    public function test_no_owner_can_capture_weight_on_another_owners_laundry_order_item(): void
    {
        [$ownerA, $storeA] = $this->makeOwnerAndStore('laundry');
        [, $storeB] = $this->makeOwnerAndStore('laundry');
        $catB = MenuCategory::create(['store_id' => $storeB->id, 'name' => 'Wash']);
        $serviceB = Menu::create(['store_id' => $storeB->id, 'category_id' => $catB->id, 'name' => 'Wash & Fold', 'price' => 5, 'pricing_unit' => 'kg', 'active' => 1]);
        $orderB = Order::create(['store_id' => $storeB->id, 'type' => 'pickup_slot', 'status' => 'received', 'track_token' => Str::lower(Str::random(32)), 'created_at' => now()]);
        $itemB = OrderItem::create(['order_id' => $orderB->id, 'item_id' => $serviceB->id, 'quantity' => 1, 'weight_kg' => 1.0]);

        $res = $this->actingAs($ownerA)->post(route('stores.service_board.weight', [$orderB, $itemB]).'?store_id='.$storeA->id, ['weight_kg' => 99]);
        $res->assertNotFound();
        $this->assertEqualsWithDelta(1.0, (float) $itemB->fresh()->weight_kg, 0.001);
    }

    // ----------------------------------------------------- food-attrs (G3)

    public function test_food_only_fields_are_never_accepted_by_a_non_food_verticals_own_item_form(): void
    {
        foreach ($this->retailShapedVerticals() as $type) {
            [$owner, $store] = $this->makeOwnerAndStore($type);
            $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

            $this->actingAs($owner)->post(route('retail.products.store', $store), [
                'category_id' => $cat->id,
                'name' => 'Widget',
                'price' => 9.99,
                // Food-only fields a malicious/careless client might tack on:
                'type' => 'nonveg',
                'is_vegan' => 1,
                'is_halal' => 1,
                'spice_level' => 3,
            ])->assertRedirect();

            $item = Menu::where('store_id', $store->id)->where('name', 'Widget')->first();
            $this->assertNotNull($item, "[$type] item should have been created");
            // RetailItemController's own validated() has no rule for any of
            // these keys at all, so they never reach MenuAdminService — the
            // item keeps whatever harmless column default the food gate
            // (F0-4) already relies on, regardless of vertical.
            $this->assertSame('veg', $item->type, "[$type] type must stay the harmless default");
            $this->assertSame(0, (int) $item->is_vegan, "[$type] is_vegan must stay unset");
            $this->assertSame(0, (int) $item->spice_level, "[$type] spice_level must stay unset");
        }

        // Laundry's own item form (ServiceItemController) — same guarantee.
        [$owner, $store] = $this->makeOwnerAndStore('laundry');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Wash']);
        $this->actingAs($owner)->post(route('laundry.services.store', $store), [
            'category_id' => $cat->id,
            'name' => 'Wash & Fold',
            'price' => 5,
            'pricing_unit' => 'kg',
            'type' => 'nonveg',
            'is_vegan' => 1,
        ])->assertRedirect();
        $service = Menu::where('store_id', $store->id)->where('name', 'Wash & Fold')->first();
        $this->assertNotNull($service);
        $this->assertSame('veg', $service->type);
        $this->assertSame(0, (int) $service->is_vegan);
    }

    public function test_shelf_and_bookla_storefronts_never_render_food_only_markup(): void
    {
        foreach ([['retail', 'shelf'], ['carparts', 'shelf'], ['laundry', 'bookla']] as [$type, $template]) {
            $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1], 'status' => 1]);
            $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
            $title = 'Store '.Str::random(6);
            $store = Store::create(['user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
            StoreOption::updateStoreOption($store->id, 'store_template', $template);
            $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
            Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Thing', 'price' => 10, 'active' => 1]);

            $response = $this->get(route('publicView', $store->slug));
            $response->assertOk();
            $response->assertDontSeeHtml('veg-icon');
            $response->assertDontSeeHtml('nonveg-icon');
            $response->assertDontSeeHtml('spice-level');
            $response->assertDontSeeHtml('Allergens');
        }
    }
}
