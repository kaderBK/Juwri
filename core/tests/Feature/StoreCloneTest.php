<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreTable;
use App\Models\VehicleFitment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Brand;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C10 — StoreCloneService now also copies the store's tables/rooms (fresh unique
 * QR key per row, every other field carried over), and the clone response counts
 * gain a `tables` key. The menu-tree clone behaviour is unchanged (that contract
 * is guarded by MenuOperationsTest).
 */
class StoreCloneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeItem(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10.00, 'active' => 1]);
    }

    private function cloneOf(User $owner, Store $source): Store
    {
        return Store::where('user_id', $owner->id)->where('id', '!=', $source->id)->firstOrFail();
    }

    /** Tables/rooms are copied, each with a FRESH unique key, and counted. */
    public function test_clone_copies_tables_with_fresh_keys(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->makeItem($store);

        StoreTable::create(['store_id' => $store->id, 'table_no' => 1, 'key' => 'srckey0000001', 'area_type' => 'table', 'label' => 'T1']);
        StoreTable::create(['store_id' => $store->id, 'table_no' => 101, 'key' => 'srckey0000002', 'area_type' => 'room', 'label' => 'Room 101']);

        $this->actingAs($owner)
            ->postJson(route('stores.clone', $store))
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('counts.tables', 2);

        $clone = $this->cloneOf($owner, $store);
        $cloneTables = StoreTable::where('store_id', $clone->id)->get();

        $this->assertCount(2, $cloneTables);
        // Fresh keys: none reuse a source key, and they are distinct from each other.
        $srcKeys = ['srckey0000001', 'srckey0000002'];
        foreach ($cloneTables as $t) {
            $this->assertNotContains($t->key, $srcKeys);
            $this->assertNotEmpty($t->key);
        }
        $this->assertCount(2, $cloneTables->pluck('key')->unique());
    }

    /** Non-key fields (table_no, area_type/room, label) carry over unchanged. */
    public function test_clone_carries_table_fields_over(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->makeItem($store);

        StoreTable::create(['store_id' => $store->id, 'table_no' => 7, 'key' => 'srckeyPatio07', 'area_type' => 'room', 'label' => 'Suite 7']);

        $this->actingAs($owner)->postJson(route('stores.clone', $store))->assertOk();

        $clone = $this->cloneOf($owner, $store);
        $t = StoreTable::where('store_id', $clone->id)->firstOrFail();

        $this->assertSame(7, (int) $t->table_no);
        $this->assertSame('room', $t->area_type);
        $this->assertSame('Suite 7', $t->label);
        $this->assertNotSame('srckeyPatio07', $t->key);
    }

    /**
     * Wave-0 F0b: the goods surfaces clone too. The cloned item points at the
     * CLONE's brand (not the source's — the old code leaked brand_id verbatim),
     * and the variant matrix + car-parts fitments carry over remapped.
     */
    public function test_clone_copies_goods_surfaces_and_remaps_brand(): void
    {
        $owner = $this->makeUser();
        $source = $this->makeRestaurant($owner);
        $source->store_type = 'carparts';
        $source->save();

        $brand = Brand::create(['store_id' => $source->id, 'name' => 'Acme', 'active' => 1, 'position' => 0]);
        $cat = MenuCategory::create(['store_id' => $source->id, 'name' => 'Filters']);
        $item = Menu::create(['store_id' => $source->id, 'category_id' => $cat->id, 'name' => 'Oil Filter', 'price' => 9.0, 'active' => 1, 'brand_id' => $brand->id]);
        MenuVariant::create(['menu_id' => $item->id, 'options' => ['Fit' => 'A'], 'sku' => 'SKU-1', 'stock_qty' => 7, 'active' => 1, 'position' => 0]);
        VehicleFitment::create(['store_id' => $source->id, 'menu_id' => $item->id, 'year' => 2016, 'make' => 'Honda', 'model' => 'Civic']);

        $this->actingAs($owner)
            ->postJson(route('stores.clone', $source))
            ->assertOk()
            ->assertJsonPath('counts.brands', 1)
            ->assertJsonPath('counts.variants', 1)
            ->assertJsonPath('counts.fitments', 1);

        $clone = $this->cloneOf($owner, $source);

        // The clone owns its own brand row; the source's brand is untouched.
        $cloneBrand = Brand::where('store_id', $clone->id)->firstOrFail();
        $this->assertNotSame($brand->id, $cloneBrand->id);

        // The cloned item's brand_id points at the CLONE's brand, never the source's.
        $cloneItem = Menu::where('store_id', $clone->id)->firstOrFail();
        $this->assertSame($cloneBrand->id, (int) $cloneItem->brand_id);

        // Variant matrix + fitments carried over, remapped onto the cloned item.
        $cloneVariant = MenuVariant::where('menu_id', $cloneItem->id)->firstOrFail();
        $this->assertSame('SKU-1', $cloneVariant->sku);
        $this->assertSame(7, (int) $cloneVariant->stock_qty);

        $cloneFitment = VehicleFitment::where('store_id', $clone->id)->firstOrFail();
        $this->assertSame($cloneItem->id, (int) $cloneFitment->menu_id);
        $this->assertSame('Civic', $cloneFitment->model);
    }

    /** The counts shape always carries `tables`, even for a store with none. */
    public function test_clone_counts_include_tables_key_when_empty(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->makeItem($store);

        $this->actingAs($owner)
            ->postJson(route('stores.clone', $store))
            ->assertOk()
            ->assertJsonPath('counts.tables', 0)
            ->assertJsonPath('counts.items', 1);
    }
}
