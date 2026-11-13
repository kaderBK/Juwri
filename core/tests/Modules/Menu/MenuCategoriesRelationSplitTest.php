<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * WI-H12 — menu_categories() is lean (parents + subcategory tree, NO menus),
 * menu_categories_with_items() carries the active-filtered items for the 15
 * legacy templates, MenuCategory::menus() itself stays UN-filtered (the clone /
 * backup path needs inactive items), and menu_categories_flat_filled costs one
 * query, not one per category.
 */
class MenuCategoriesRelationSplitTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::create([
            'name' => 'Owner '.Str::random(4),
            'email' => Str::lower(Str::random(8)).'@test.dev',
            'password' => bcrypt('secret'),
        ]);
        $title = 'Store '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'Test',
            'description' => 'Test', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 Test St', 'phone' => '1234567890',
        ]);
    }

    private function category(Store $store, string $name, int $position, ?int $parent = null): MenuCategory
    {
        return MenuCategory::create([
            'store_id' => $store->id, 'name' => $name,
            'position' => $position, 'parent' => $parent, 'active' => '1',
        ]);
    }

    private function item(MenuCategory $cat, string $active = '1'): Menu
    {
        return Menu::create([
            'store_id' => $cat->store_id, 'category_id' => $cat->id,
            'name' => 'Item '.Str::random(4), 'price' => 5, 'active' => $active,
        ]);
    }

    /** A store with a parent category, one subcategory, and items in both. */
    private function tree(): Store
    {
        $store = $this->store();
        $parent = $this->category($store, 'Mains', 1);
        $this->item($parent);
        $sub = $this->category($store, 'Sides', 1, $parent->id);
        $this->item($sub);

        return $store;
    }

    public function test_menu_categories_does_not_eager_load_items(): void
    {
        $store = Store::with('menu_categories')->find($this->tree()->id);
        $parent = $store->menu_categories->first();

        $this->assertFalse($parent->relationLoaded('menus'));
        $this->assertFalse($parent->subcategories->first()->relationLoaded('menus'));
    }

    public function test_reading_menu_categories_costs_two_queries(): void
    {
        $id = $this->tree()->id;
        $store = Store::find($id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $store->menu_categories->each(fn ($c) => $c->subcategories->all());

        $this->assertSame(2, count(DB::getQueryLog()));
    }

    public function test_menu_categories_with_items_loads_items_for_parents_and_subcategories(): void
    {
        $store = Store::with('menu_categories_with_items')->find($this->tree()->id);
        $parent = $store->menu_categories_with_items->first();

        $this->assertTrue($parent->relationLoaded('menus'));
        $this->assertTrue($parent->subcategories->first()->relationLoaded('menus'));
        $this->assertCount(1, $parent->menus);
        $this->assertCount(1, $parent->subcategories->first()->menus);
    }

    public function test_menu_categories_with_items_excludes_inactive_items(): void
    {
        $store = $this->store();
        $parent = $this->category($store, 'Mains', 1);
        $this->item($parent, '1');
        $this->item($parent, '0');
        $sub = $this->category($store, 'Sides', 1, $parent->id);
        $this->item($sub, '1');
        $this->item($sub, '0');

        $fresh = Store::with('menu_categories_with_items')->find($store->id);
        $cat = $fresh->menu_categories_with_items->first();

        $this->assertCount(1, $cat->menus);
        $this->assertCount(1, $cat->subcategories->first()->menus);
    }

    public function test_menu_categories_with_items_costs_four_queries(): void
    {
        $id = $this->tree()->id;
        $store = Store::find($id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $store->menu_categories_with_items->each(fn ($c) => $c->menus->all());

        $this->assertSame(4, count(DB::getQueryLog()));
    }

    public function test_menu_categories_relation_is_still_top_level_only(): void
    {
        $store = Store::with('menu_categories')->find($this->tree()->id);

        // A subcategory (parent != null) must never appear at the top level.
        $this->assertTrue($store->menu_categories->every(fn ($c) => $c->parent === null));
    }

    public function test_the_menus_relation_itself_is_not_active_filtered(): void
    {
        $store = $this->store();
        $parent = $this->category($store, 'Mains', 1);
        $this->item($parent, '1');
        $this->item($parent, '0');

        // The exact eager-load spec used by the clone path (StoreController).
        $fresh = Store::with(['menu_categories.menus', 'menu_categories.subcategories.menus'])->find($store->id);

        // Fence for §1d(ii): moving `active` into MenuCategory::menus() reds this.
        $this->assertCount(2, $fresh->menu_categories->first()->menus);
    }

    public function test_menu_categories_flat_filled_loads_items_in_one_query_not_per_category(): void
    {
        $store = $this->store();
        foreach (range(1, 3) as $p) {
            $parent = $this->category($store, "P{$p}", $p);
            $this->item($parent, '1');
            $sub = $this->category($store, "S{$p}", 1, $parent->id);
            $this->item($sub, '1');
        }

        $fresh = Store::with('menu_categories')->find($store->id); // menus NOT eager-loaded

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fresh->menu_categories_flat_filled;

        $this->assertSame(1, count(DB::getQueryLog()), 'loadMissing must batch menus into ONE query, not one per category');
    }
}
