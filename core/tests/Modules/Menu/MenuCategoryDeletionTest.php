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
use Juwri\Modules\Menu\Services\MenuAdminService;
use Tests\TestCase;

/**
 * Deleting a category used to remove its own items but leave its subcategories
 * pointing at a row that no longer exists — the storefront's live source of
 * orphaned menu data (rev/audit-residue/DATA-REPAIR-PLAN.md §3.2/§3.3).
 *
 * The contract these tests defend:
 *   - without a mode, deleting a parent-with-subcategories is refused, not silent
 *   - cascade removes the subcategories and their items
 *   - reparent promotes the subcategories to top-level
 *   - deleting a leaf is unchanged
 *   - deleting a STORE leaves no category or item behind (Post::deleting)
 */
class MenuCategoryDeletionTest extends TestCase
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
            'phone' => '1234567890',
        ]);
    }

    private function category(Store $store, string $name, ?int $parent = null): MenuCategory
    {
        return MenuCategory::create([
            'store_id' => $store->id, 'name' => $name,
            'position' => 1, 'parent' => $parent, 'active' => '1',
        ]);
    }

    private function item(MenuCategory $cat): Menu
    {
        return Menu::create([
            'store_id' => $cat->store_id, 'category_id' => $cat->id,
            'name' => 'Item '.Str::random(4), 'price' => 5, 'active' => '1',
        ]);
    }

    public function test_deleting_a_parent_with_subcategories_is_refused_without_a_mode(): void
    {
        $store = $this->store();
        $parent = $this->category($store, 'Drinks');
        $child = $this->category($store, 'Cold', $parent->id);
        $this->item($child);

        $result = MenuAdminService::deleteCategory($parent);

        $this->assertFalse($result['ok']);
        $this->assertSame('has_subcategories', $result['reason']);
        $this->assertSame(1, $result['subcategories']);
        // nothing was touched
        $this->assertDatabaseHas('categories', ['id' => $parent->id]);
        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent' => $parent->id]);
    }

    public function test_cascade_deletes_subcategories_and_their_items(): void
    {
        $store = $this->store();
        $parent = $this->category($store, 'Drinks');
        $child = $this->category($store, 'Cold', $parent->id);
        $childItem = $this->item($child);
        $parentItem = $this->item($parent);

        $result = MenuAdminService::deleteCategory($parent, 'cascade');

        $this->assertTrue($result['ok']);
        $this->assertDatabaseMissing('categories', ['id' => $parent->id]);
        $this->assertDatabaseMissing('categories', ['id' => $child->id]);
        $this->assertDatabaseMissing('items', ['id' => $childItem->id]);
        $this->assertDatabaseMissing('items', ['id' => $parentItem->id]);
    }

    public function test_reparent_promotes_subcategories_to_top_level(): void
    {
        $store = $this->store();
        $parent = $this->category($store, 'Drinks');
        $child = $this->category($store, 'Cold', $parent->id);
        $childItem = $this->item($child);

        $result = MenuAdminService::deleteCategory($parent, 'reparent');

        $this->assertTrue($result['ok']);
        $this->assertDatabaseMissing('categories', ['id' => $parent->id]);
        // child survives, now top-level, item intact
        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent' => null]);
        $this->assertDatabaseHas('items', ['id' => $childItem->id]);
    }

    public function test_deleting_a_leaf_category_is_unchanged(): void
    {
        $store = $this->store();
        $leaf = $this->category($store, 'Snacks');
        $item = $this->item($leaf);

        // A leaf with items: forceItems mirrors the dashboard button's long-standing
        // ability to delete a category outright.
        $result = MenuAdminService::deleteCategory($leaf, 'refuse', forceItems: true);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseMissing('categories', ['id' => $leaf->id]);
        $this->assertDatabaseMissing('items', ['id' => $item->id]);
    }

    public function test_a_leaf_with_items_is_refused_without_force(): void
    {
        $store = $this->store();
        $leaf = $this->category($store, 'Snacks');
        $this->item($leaf);

        $result = MenuAdminService::deleteCategory($leaf);

        $this->assertFalse($result['ok']);
        $this->assertSame('has_items', $result['reason']);
        $this->assertDatabaseHas('categories', ['id' => $leaf->id]);
    }

    public function test_deleting_a_store_leaves_no_orphan_category_or_item(): void
    {
        // Invariant, not a strict regression guard: the sqlite test schema enforces
        // the parent/category_id foreign keys that production MySQL does not
        // (rev/audit-residue ledger #3), so its ON DELETE CASCADE also satisfies
        // this end-state even without the Post::deleting fix. The fix matters on
        // MySQL, where no FK exists — this asserts the intended end-state regardless.
        $store = $this->store();
        $parent = $this->category($store, 'Drinks');
        $child = $this->category($store, 'Cold', $parent->id);
        $topItem = $this->item($parent);
        $subItem = $this->item($child);

        $store->delete();

        $this->assertDatabaseMissing('categories', ['id' => $parent->id]);
        $this->assertDatabaseMissing('categories', ['id' => $child->id]);
        $this->assertDatabaseMissing('items', ['id' => $topItem->id]);
        $this->assertDatabaseMissing('items', ['id' => $subItem->id]);
    }
}
