<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * menu_categories() is top-level only, but a menu item keys off its own category_id,
 * which may be a subcategory id. menu_categories_flat is the one place that closes
 * that gap.
 *
 * The contract these tests defend:
 *   - order is parent, that parent's children, next parent — themes render this order
 *   - it costs NOT ONE query more than menu_categories, which it is built from
 *   - a store with no subcategories sees exactly what it saw before
 *   - a subcategory with a dangling parent stays unreachable, and nothing crashes
 */
class PostMenuCategoriesFlatTest extends TestCase
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

    public function test_flat_order_is_parent_then_that_parents_children_then_next_parent(): void
    {
        $store = $this->store();

        $drinks = $this->category($store, 'Drinks', 1);
        $this->category($store, 'Hot', 2, $drinks->id);
        $this->category($store, 'Cold', 1, $drinks->id);   // position, not insertion, decides

        $food = $this->category($store, 'Food', 2);
        $this->category($store, 'Mains', 1, $food->id);

        $this->category($store, 'Desserts', 3);            // a childless parent still appears

        $this->assertSame(
            ['Drinks', 'Cold', 'Hot', 'Food', 'Mains', 'Desserts'],
            $store->menu_categories_flat->pluck('name')->all()
        );
    }

    /** The whole point: subcategories are reachable, so items keyed to them render. */
    public function test_it_contains_every_category_the_relation_hides(): void
    {
        $store = $this->store();
        $parent = $this->category($store, 'Drinks', 1);
        $child = $this->category($store, 'Cold', 1, $parent->id);

        $this->assertSame([$parent->id], $store->menu_categories->pluck('id')->all());
        $this->assertSame([$parent->id, $child->id], $store->menu_categories_flat->pluck('id')->all());
    }

    public function test_a_store_with_no_subcategories_returns_the_same_set_as_before(): void
    {
        $store = $this->store();
        $this->category($store, 'Drinks', 1);
        $this->category($store, 'Food', 2);

        $this->assertSame(
            $store->menu_categories->pluck('id')->all(),
            $store->menu_categories_flat->pluck('id')->all()
        );
    }

    public function test_an_empty_store_is_an_empty_collection_not_a_crash(): void
    {
        $store = $this->store();

        $this->assertCount(0, $store->menu_categories_flat);
    }

    /**
     * A subcategory whose parent is not a top-level category of this store must stay
     * unreachable, exactly as it is today. Surfacing one would resurrect a category the
     * owner believes is gone.
     *
     * Production's shape is a parent row that no longer exists (455 such rows). That
     * literal row cannot be built here: this migration declares
     * parent -> menu_categories ON DELETE CASCADE and sqlite enforces it, while the live
     * InnoDB table carries NO foreign keys at all — the create is guarded by hasTable(),
     * so the constraint never materialised, which is how the 455 rows arose. Nor can the
     * FK be switched off: sqlite ignores PRAGMA foreign_keys inside RefreshDatabase's
     * transaction. So the fixture uses the shape the FK does permit — a parent the store
     * cannot load — which the accessor cannot distinguish from an absent one: both are
     * simply "not among the loaded parents".
     *
     * The orphan below carries store_id = this store, so an accessor rewritten as
     * "every category of this restaurant" would emit it and fail here. That is the point.
     */
    public function test_a_subcategory_whose_parent_the_store_cannot_load_stays_unreachable(): void
    {
        $store = $this->store();
        $live = $this->category($store, 'Drinks', 1);

        // A real parent row that menu_categories() will never load: store_id NULL.
        $unloadable = MenuCategory::create([
            'store_id' => null, 'name' => 'Ghost', 'position' => 1, 'parent' => null, 'active' => '1',
        ]);
        $orphan = $this->category($store, 'Orphan', 1, $unloadable->id);

        $flat = $store->menu_categories_flat;

        $this->assertSame([$live->id], $flat->pluck('id')->all());
        $this->assertNotContains($orphan->id, $flat->pluck('id')->all(), 'an unreachable subcategory was resurrected');
    }

    /** The design constraint: built from the already-loaded relation, never a new query. */
    public function test_it_costs_no_extra_query_versus_menu_categories(): void
    {
        $store = $this->store();
        $parent = $this->category($store, 'Drinks', 1);
        $this->category($store, 'Cold', 1, $parent->id);
        $this->category($store, 'Hot', 2, $parent->id);

        // flushQueryLog between blocks: disableQueryLog does NOT clear the log, so a
        // second measurement silently counts the first one's queries too.
        $baseline = Store::find($store->id);
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $baseline->menu_categories->each(fn ($c) => $c->subcategories->all());
        $relationQueries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $subject = Store::find($store->id);
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $subject->menu_categories_flat->all();
        $flatQueries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame(
            $relationQueries,
            $flatQueries,
            "menu_categories cost {$relationQueries} queries, the accessor cost {$flatQueries}"
        );
        // H12: menu_categories() no longer eager-loads menus, so the shape is
        // parents + subcategories = 2 (was 4: + subcategory items + parent items).
        $this->assertSame(2, $flatQueries, 'the eager-load shape changed; re-measure before trusting this');
    }

    /** Already eager-loaded is the hot path: the accessor must then be pure memory. */
    public function test_it_issues_zero_queries_when_the_relation_is_already_loaded(): void
    {
        $store = $this->store();
        $parent = $this->category($store, 'Drinks', 1);
        $this->category($store, 'Cold', 1, $parent->id);

        $loaded = Store::with('menu_categories')->find($store->id);

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $loaded->menu_categories_flat->all();
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame(0, $queries, 'the accessor queried despite an eager-loaded relation');
    }

    /** shouldCache(): a blade loop reads this repeatedly and must not recompute. */
    public function test_repeated_access_is_cached_and_never_requeries(): void
    {
        $store = $this->store();
        $parent = $this->category($store, 'Drinks', 1);
        $this->category($store, 'Cold', 1, $parent->id);

        $first = $store->menu_categories_flat;

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        for ($i = 0; $i < 25; $i++) {
            $again = $store->menu_categories_flat;
        }
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame(0, $queries, 'repeated access requeried; shouldCache() is not holding');
        $this->assertSame($first, $again, 'shouldCache() must hand back the same instance');
    }
}
