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
 * MenuCategory::menus() was a bare hasMany on category_id. 11 live rows carry a
 * store_id that disagrees with their category's, and the 15 legacy themes render
 * $category->menus straight into a public page — so store 2042's menu served store
 * 2038's item to diners.
 *
 * The contract these tests defend:
 *   - a category never yields an item belonging to another store
 *   - it still yields all of its own items, in position order
 *   - the filter survives eager loading, including across stores, where a relation
 *     resolves its constraints once against the first model and reuses them
 *   - Post::menu_categories_flat, which reads through this relation, is clean too
 */
class MenuCategoryTenantScopeTest extends TestCase
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
            'cover_image' => 'default.png', 'address' => '1 Test St',
            'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function category(Store $store, string $name, int $position, ?int $parent = null): MenuCategory
    {
        return MenuCategory::create([
            'store_id' => $store->id,
            'name' => $name,
            'position' => $position,
            'parent' => $parent,
            'active' => '1',
        ]);
    }

    /** An item placed in $category, but owned by $owner — the live mis-parented shape. */
    private function item(MenuCategory $category, Store $owner, string $name, int $position): Menu
    {
        return Menu::create([
            'store_id' => $owner->id,
            'category_id' => $category->id,
            'name' => $name,
            'price' => 10,
            'position' => $position,
            'active' => '1',
        ]);
    }

    public function test_a_category_does_not_yield_an_item_belonging_to_another_store(): void
    {
        $victim = $this->store();
        $other = $this->store();

        $category = $this->category($victim, 'Drinks', 1);
        $this->item($category, $victim, 'Own Water', 1);
        $intruder = $this->item($category, $other, 'Foreign Pepsi', 2);

        $names = $category->menus()->pluck('name')->all();

        $this->assertSame(['Own Water'], $names);
        $this->assertNotContains('Foreign Pepsi', $names, 'a diner was served another store\'s item');
        $this->assertTrue($category->menus->every(fn ($m) => (int) $m->store_id === (int) $victim->id));

        // the row still exists — this is a read-time filter, not a delete
        $this->assertDatabaseHas('items', ['id' => $intruder->id]);
    }

    public function test_a_category_still_yields_all_of_its_own_items_in_position_order(): void
    {
        $store = $this->store();
        $category = $this->category($store, 'Drinks', 1);

        $this->item($category, $store, 'Third', 3);
        $this->item($category, $store, 'First', 1);
        $this->item($category, $store, 'Second', 2);

        $this->assertSame(['First', 'Second', 'Third'], $category->menus()->pluck('name')->all());
    }

    public function test_the_filter_survives_eager_loading_across_stores(): void
    {
        // The regression a per-instance where() would cause: a relation resolves its
        // constraints against the FIRST model and reuses them for every other one, so
        // baking in store A's id filters store B's category against store A.
        $storeA = $this->store();
        $storeB = $this->store();

        $catA = $this->category($storeA, 'A Drinks', 1);
        $catB = $this->category($storeB, 'B Drinks', 1);

        $this->item($catA, $storeA, 'A Own', 1);
        $this->item($catA, $storeB, 'A Leaked', 2);
        $this->item($catB, $storeB, 'B Own', 1);
        $this->item($catB, $storeA, 'B Leaked', 2);

        $loaded = MenuCategory::with('menus')
            ->whereIn('id', [$catA->id, $catB->id])
            ->get()
            ->keyBy('id');

        $this->assertSame(['A Own'], $loaded[$catA->id]->menus->pluck('name')->all());
        $this->assertSame(['B Own'], $loaded[$catB->id]->menus->pluck('name')->all(), 'store B was filtered against store A');
    }

    public function test_menu_categories_flat_never_surfaces_a_foreign_item(): void
    {
        $victim = $this->store();
        $other = $this->store();

        $parent = $this->category($victim, 'Drinks', 1);
        $child = $this->category($victim, 'Cold', 1, $parent->id);

        $this->item($parent, $victim, 'Parent Own', 1);
        $this->item($parent, $other, 'Parent Foreign', 2);
        $this->item($child, $victim, 'Child Own', 1);
        $this->item($child, $other, 'Child Foreign', 2);

        $rendered = $victim->menu_categories_flat
            ->flatMap(fn ($c) => $c->menus->pluck('name'))
            ->all();

        $this->assertSame(['Parent Own', 'Child Own'], $rendered);
    }
}
