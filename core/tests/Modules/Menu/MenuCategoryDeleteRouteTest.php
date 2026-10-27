<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The dashboard delete-category endpoint (ManagesMenu::deleteCategory) drives the
 * subcategory picker: deleting a parent-with-subcategories returns needs_choice so
 * the owner decides, rather than silently orphaning the children.
 */
class MenuCategoryDeleteRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id,
            'status' => 1, 'email_verified_at' => now()]);
    }

    private function storeFixture(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::random(5),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234']);
    }

    private function category(Store $store, ?int $parent = null): MenuCategory
    {
        return MenuCategory::create(['store_id' => $store->id,
            'name' => 'C'.Str::random(4), 'position' => 1, 'parent' => $parent, 'active' => '1']);
    }

    public function test_deleting_a_parent_with_subcategories_asks_the_owner(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $parent = $this->category($store);
        $child = $this->category($store, $parent->id);

        $res = $this->actingAs($owner)
            ->postJson(route('stores.deleteCategory', $store->id), ['id' => $parent->id]);

        $res->assertOk()
            ->assertJson(['success' => false, 'needs_choice' => true, 'subcategories' => 1]);

        // nothing deleted while the owner decides
        $this->assertDatabaseHas('categories', ['id' => $parent->id]);
        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent' => $parent->id]);
    }

    public function test_reparent_mode_promotes_children_and_deletes_the_parent(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $parent = $this->category($store);
        $child = $this->category($store, $parent->id);

        $res = $this->actingAs($owner)
            ->postJson(route('stores.deleteCategory', $store->id), ['id' => $parent->id, 'mode' => 'reparent']);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseMissing('categories', ['id' => $parent->id]);
        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent' => null]);
    }

    public function test_cascade_mode_deletes_children(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $parent = $this->category($store);
        $child = $this->category($store, $parent->id);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $child->id,
            'name' => 'X', 'price' => 3, 'active' => '1']);

        $res = $this->actingAs($owner)
            ->postJson(route('stores.deleteCategory', $store->id), ['id' => $parent->id, 'mode' => 'cascade']);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseMissing('categories', ['id' => $child->id]);
        $this->assertDatabaseMissing('items', ['id' => $item->id]);
    }

    public function test_a_leaf_category_deletes_without_a_prompt(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $leaf = $this->category($store);

        $res = $this->actingAs($owner)
            ->postJson(route('stores.deleteCategory', $store->id), ['id' => $leaf->id]);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseMissing('categories', ['id' => $leaf->id]);
    }
}
