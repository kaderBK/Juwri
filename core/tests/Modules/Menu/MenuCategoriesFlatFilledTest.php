<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * menu_categories_flat_filled is menu_categories_flat minus the categories that
 * hold no active item of their own — the empty chips several flat themes each
 * hand-rolled a filter to drop.
 *
 * The contract:
 *   - a category with an active item survives; an empty one is dropped
 *   - a container parent (items only in its subcategories) is dropped, its
 *     item-bearing children survive — flat themes chip each separately
 *   - an inactive-only category is dropped (active is '1'/'0', compared as '1')
 *   - it equals the theme-side filter (active items' distinct category_ids)
 *   - it costs no query beyond menu_categories_flat
 */
class MenuCategoriesFlatFilledTest extends TestCase
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

    public function test_empty_and_container_categories_are_dropped_but_their_filled_children_survive(): void
    {
        $store = $this->store();

        $filled = $this->category($store, 'Snacks', 1);          // has an item -> stays
        $this->item($filled);

        $empty = $this->category($store, 'Empty', 2);            // no items -> dropped

        $container = $this->category($store, 'Drinks', 3);       // 0 direct items -> dropped
        $cold = $this->category($store, 'Cold', 1, $container->id);
        $this->item($cold);                                     // child has an item -> stays

        $ids = $store->menu_categories_flat_filled->pluck('id')->all();

        $this->assertContains($filled->id, $ids);
        $this->assertContains($cold->id, $ids);
        $this->assertNotContains($empty->id, $ids, 'an empty category kept its chip');
        $this->assertNotContains($container->id, $ids, 'a container parent with no direct items kept its chip');
    }

    public function test_an_inactive_only_category_is_dropped(): void
    {
        $store = $this->store();
        $cat = $this->category($store, 'Hidden', 1);
        $this->item($cat, '0');                                 // only an inactive item

        $this->assertNotContains($cat->id, $store->menu_categories_flat_filled->pluck('id')->all());
    }

    public function test_it_equals_the_theme_side_filter(): void
    {
        $store = $this->store();
        $a = $this->category($store, 'A', 1);
        $this->item($a);
        $this->category($store, 'B', 2);                          // empty
        $c = $this->category($store, 'C', 3);
        $this->item($c);
        $this->item($c, '0');

        // what picnic/scoop compute from $total_menus:
        $activeCatIds = Menu::where('store_id', $store->id)->where('active', '1')
            ->pluck('category_id')->map(fn ($x) => (int) $x)->unique()->sort()->values()->all();

        $accessor = $store->menu_categories_flat_filled
            ->pluck('id')->map(fn ($x) => (int) $x)->sort()->values()->all();

        $this->assertSame($activeCatIds, $accessor);
    }

    public function test_it_costs_no_query_beyond_the_flat_accessor(): void
    {
        $store = $this->store();
        $cat = $this->category($store, 'A', 1);
        $this->item($cat);

        $fresh = Store::with(['menu_categories', 'menu_categories.menus', 'menu_categories.subcategories.menus'])->find($store->id);
        $fresh->menu_categories_flat;                            // prime the eager load

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $fresh->menu_categories_flat_filled;
        $count = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame(0, $count, 'menu_categories_flat_filled fired a query');
    }
}
