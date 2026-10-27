<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use Juwri\Modules\Menu\Models\Brand;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane A1 — hybrid attribute schema (DECISIONS A1).
 *
 * `menu.brand_id`/`weight`/`dimensions`/`attributes` round-trip cleanly and a
 * legacy (food) item that never sets them is completely unaffected.
 */
class RetailAttributeSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(string $type = 'retail'): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_brand_weight_dimensions_and_attributes_round_trip(): void
    {
        $store = $this->makeStore('retail');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        $brand = Brand::create(['store_id' => $store->id, 'name' => 'Acme']);

        $item = Menu::create([
            'store_id' => $store->id,
            'category_id' => $cat->id,
            'name' => 'Widget',
            'price' => 19.99,
            'brand_id' => $brand->id,
            'weight' => 1.250,
            'dimensions' => ['length' => 10, 'width' => 5, 'height' => 2, 'unit' => 'cm'],
            'attributes' => ['fabric' => 'cotton', 'origin' => 'PT'],
        ]);

        $fresh = Menu::find($item->id);
        $this->assertSame($brand->id, $fresh->brand_id);
        $this->assertSame('Acme', $fresh->brand->name);
        $this->assertEqualsWithDelta(1.250, (float) $fresh->weight, 0.001);
        $this->assertSame(['length' => 10, 'width' => 5, 'height' => 2, 'unit' => 'cm'], $fresh->dimensions);
        $this->assertSame(['fabric' => 'cotton', 'origin' => 'PT'], $fresh->attributes);
    }

    public function test_legacy_item_without_attributes_is_unaffected(): void
    {
        $store = $this->makeStore('restaurant');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10]);

        $fresh = Menu::find($item->id);
        $this->assertNull($fresh->brand_id);
        $this->assertNull($fresh->weight);
        $this->assertNull($fresh->dimensions);
        $this->assertNull($fresh->attributes);
    }

    public function test_brand_belongs_to_one_store(): void
    {
        $storeA = $this->makeStore('retail');
        $storeB = $this->makeStore('cosmetic');
        $brandA = Brand::create(['store_id' => $storeA->id, 'name' => 'Acme']);
        $brandB = Brand::create(['store_id' => $storeB->id, 'name' => 'Acme']);

        $this->assertNotSame($brandA->id, $brandB->id);
        $this->assertSame($storeA->id, $brandA->store_id);
        $this->assertSame($storeB->id, $brandB->store_id);
    }
}
