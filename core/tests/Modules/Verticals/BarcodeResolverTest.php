<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Services\Catalog\BarcodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane A6 — barcode source-of-truth resolve (O-BARCODE).
 *
 * Decision taken: ALIAS `menu.barcode` onto the existing `menu.plu` column
 * (POS already scans `plu` as the barcode — PosController's scanner wedge),
 * rather than adding a duplicate item-level column. Variants get a real
 * `barcode` column (A2) since they had nothing to alias.
 */
class BarcodeResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => 'retail', 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_barcode_is_an_alias_for_plu_not_a_duplicate_column(): void
    {
        $this->assertFalse(Schema::hasColumn('items', 'barcode'), 'menu must NOT gain a duplicate barcode column — plu is aliased instead');

        $store = $this->makeStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Widget', 'price' => 5, 'barcode' => 'ABC123']);

        $this->assertSame('ABC123', $item->fresh()->plu, 'writing ->barcode must land on the plu column POS already scans');
    }

    public function test_resolver_finds_an_item_by_barcode(): void
    {
        $store = $this->makeStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Widget', 'price' => 5, 'plu' => 'ITEM-1']);

        $result = app(BarcodeResolver::class)->resolve('ITEM-1', $store->id);

        $this->assertNotNull($result);
        $this->assertSame('item', $result['type']);
        $this->assertSame('Widget', $result['menu']->getRawOriginal('name'));
    }

    public function test_resolver_finds_a_variant_by_its_own_barcode(): void
    {
        $store = $this->makeStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Shirts']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'T-Shirt', 'price' => 25]);
        $variant = MenuVariant::create(['menu_id' => $item->id, 'price' => 25, 'options' => (object) ['Size' => 'M'], 'barcode' => 'VAR-9']);

        $result = app(BarcodeResolver::class)->resolve('VAR-9', $store->id);

        $this->assertNotNull($result);
        $this->assertSame('variant', $result['type']);
        $this->assertSame($variant->id, $result['variant']->id);
        $this->assertSame($item->id, $result['menu']->id);
    }

    public function test_resolver_is_scoped_to_the_store(): void
    {
        $storeA = $this->makeStore();
        $storeB = $this->makeStore();
        $catA = MenuCategory::create(['store_id' => $storeA->id, 'name' => 'Cat']);
        Menu::create(['store_id' => $storeA->id, 'category_id' => $catA->id, 'name' => 'Widget', 'price' => 5, 'plu' => 'SHARED-CODE']);

        $this->assertNull(app(BarcodeResolver::class)->resolve('SHARED-CODE', $storeB->id));
    }

    public function test_unknown_code_resolves_to_null(): void
    {
        $store = $this->makeStore();
        $this->assertNull(app(BarcodeResolver::class)->resolve('NOPE', $store->id));
    }
}
