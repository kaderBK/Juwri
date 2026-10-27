<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Juwri\Modules\Menu\Models\GlobalMenuCategory;
use Juwri\Modules\Menu\Models\GlobalMenuExtra;
use App\Models\MenuCategory;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Services\PlatformCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WI-G4: adopting a platform-catalog entry copies it into the store's own scoped
 * rows (never shares cross-tenant), with provenance so a re-adopt updates rather
 * than duplicates.
 */
class PlatformCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => (object) [], 'languages' => collect()]);
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
    }

    private function store(User $u, string $slug): Store
    {
        return Store::create([
            'user_id' => $u->id, 'title' => 'S ' . $slug, 'slug' => $slug, 'color' => '#fff',
            'sub_title' => 's', 'description' => 'd', 'main_image' => 'x.png', 'cover_image' => 'x.png',
            'address' => 'a', 'phone' => '1',
        ]);
    }

    public function test_adopt_copies_category_tree_scoped_to_store(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $a = $this->store($owner, 'cat-a');
        $b = $this->store($owner, 'cat-b');
        $drinks = GlobalMenuCategory::create(['name' => 'Drinks', 'position' => 1, 'active' => true]);
        GlobalMenuCategory::create(['name' => 'Coffee', 'parent_id' => $drinks->id, 'active' => true]);

        app(PlatformCatalogService::class)->adoptCategory($drinks, $a);

        $adopted = MenuCategory::where('store_id', $a->id)->get();
        $this->assertCount(2, $adopted, 'category + sub-category copied');
        $parent = $adopted->firstWhere('name', 'Drinks');
        $child = $adopted->firstWhere('name', 'Coffee');
        $this->assertSame($drinks->id, (int) $parent->source_global_id);
        $this->assertSame((int) $parent->id, (int) $child->parent, 'sub-category re-parented to the local copy');

        // Store B is untouched — no cross-tenant share.
        $this->assertSame(0, MenuCategory::where('store_id', $b->id)->count());
    }

    public function test_re_adopt_is_provenance_aware_no_duplicate(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $a = $this->store($owner, 'reup');
        $cat = GlobalMenuCategory::create(['name' => 'Mains', 'active' => true]);
        $svc = app(PlatformCatalogService::class);

        $svc->adoptCategory($cat, $a);
        $cat->update(['name' => 'Main Dishes']); // curator renames
        $svc->adoptCategory($cat, $a);           // owner re-adopts to pull the update

        $rows = MenuCategory::where('store_id', $a->id)->get();
        $this->assertCount(1, $rows, 'no duplicate on re-adopt');
        $this->assertSame('Main Dishes', $rows->first()->name, 'copy refreshed from the catalog');
    }

    public function test_owner_catalog_page_renders_with_adopt_controls(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $a = $this->store($owner, 'lib-page');
        GlobalMenuCategory::create(['name' => 'Breakfast', 'active' => true]);
        GlobalMenuExtra::create(['title' => 'Extra Sauce', 'price' => 3, 'active' => true]);

        $this->actingAs($owner);
        session(['dashboard_store_id' => $a->id]);
        $res = $this->get(route('platform-catalog.index'));
        $res->assertOk();
        $res->assertSee('Breakfast');
        $res->assertSee('Extra Sauce');
        $res->assertSeeHtml(route('platform-catalog.adopt-category', GlobalMenuCategory::first()->id));
    }

    public function test_owner_route_adopts_into_current_store(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $a = $this->store($owner, 'route-adopt');
        $extra = GlobalMenuExtra::create(['title' => 'Extra Cheese', 'price' => 2, 'active' => true]);

        $this->actingAs($owner);
        session(['dashboard_store_id' => $a->id]);
        $this->post(route('platform-catalog.adopt-extra', $extra->id))->assertRedirect();

        $this->assertSame(1, MenuExtra::where('store_id', $a->id)->where('source_global_id', $extra->id)->count());
    }
}
