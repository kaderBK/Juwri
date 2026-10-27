<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ImageMenu;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\TestCase;

/**
 * Cross-tenant IDOR guard for the menu-management dashboard. Every mutating
 * endpoint proves ownership of the {store} in the ROUTE, then loads the
 * child model by a body-supplied id. Without scoping that child to the owned
 * restaurant, owner A can edit/delete owner B's menu data. These tests drive
 * each endpoint as attacker A against victim B's rows and assert B is untouched.
 */
class MenuIdorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{0:User,1:Store,2:Menu} owner, their restaurant, one of their menus */
    private function tenant(): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::factory()->create(['user_id' => $user->id]);
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        $menu = Menu::create([
            'store_id' => $store->id, 'name' => 'Item', 'type' => 'veg',
            'price' => 10, 'category_id' => $category->id,
        ]);

        return [$user, $store, $menu];
    }

    public function test_owner_cannot_edit_another_tenants_menu_item(): void
    {
        [$attacker, $rA] = $this->tenant();
        [, , $victimMenu] = $this->tenant();

        $this->actingAs($attacker)->post(route('stores.updateMenuItem', $rA->id), [
            'id' => $victimMenu->id, 'category_id' => 1, 'name' => 'HACKED', 'price' => 0, 'type' => 'veg',
        ]);

        $victimMenu->refresh();
        $this->assertSame('Item', $victimMenu->name, 'Cross-tenant menu item name was overwritten.');
        $this->assertSame(10.0, (float) $victimMenu->price, 'Cross-tenant menu item price was overwritten.');
    }

    public function test_owner_cannot_edit_another_tenants_variant_price(): void
    {
        [$attacker, $rA, $mA] = $this->tenant();
        [, , $victimMenu] = $this->tenant();
        $variant = MenuVariant::create(['menu_id' => $victimMenu->id, 'price' => 5, 'options' => []]);

        $this->actingAs($attacker)->post(route('stores.menuUpdateVariant', [$rA->id, $mA->id]), [
            'id' => $variant->id, 'price' => 0, 'options' => [],
        ]);

        $this->assertSame(5.0, (float) $variant->fresh()->price, 'Cross-tenant variant price was overwritten.');
    }

    public function test_owner_cannot_edit_another_tenants_variant_option(): void
    {
        [$attacker, $rA, $mA] = $this->tenant();
        [, $rB, $victimMenu] = $this->tenant();
        $option = MenuVariantOption::create([
            'title' => 'Size', 'options' => ['S'], 'menu_id' => $victimMenu->id, 'store_id' => $rB->id,
        ]);

        $this->actingAs($attacker)->post(route('stores.menuUpdateVariantOption', [$rA->id, $mA->id]), [
            'id' => $option->id, 'title' => 'HACKED', 'options' => 'X',
        ]);

        $this->assertSame('Size', $option->fresh()->title, 'Cross-tenant variant option was overwritten.');
    }

    public function test_owner_cannot_delete_another_tenants_variant_option(): void
    {
        [$attacker, $rA, $mA] = $this->tenant();
        [, $rB, $victimMenu] = $this->tenant();
        $option = MenuVariantOption::create([
            'title' => 'Size', 'options' => ['S'], 'menu_id' => $victimMenu->id, 'store_id' => $rB->id,
        ]);

        $this->actingAs($attacker)->post(route('stores.menuDeleteVariantOption', [$rA->id, $mA->id]), [
            'id' => $option->id,
        ]);

        $this->assertNotNull(MenuVariantOption::find($option->id), 'Cross-tenant variant option was deleted.');
    }

    public function test_owner_cannot_edit_another_tenants_extra(): void
    {
        [$attacker, $rA, $mA] = $this->tenant();
        [, $rB, $victimMenu] = $this->tenant();
        $extra = MenuExtra::create([
            'title' => 'Cheese', 'price' => 2, 'menu_id' => $victimMenu->id, 'store_id' => $rB->id,
        ]);

        $this->actingAs($attacker)->post(route('stores.menuUpdateExtra', [$rA->id, $mA->id]), [
            'id' => $extra->id, 'title' => 'HACKED', 'price' => 0,
        ]);

        $fresh = $extra->fresh();
        $this->assertSame('Cheese', $fresh->title, 'Cross-tenant extra title was overwritten.');
        $this->assertSame(2.0, (float) $fresh->price, 'Cross-tenant extra price was overwritten.');
    }

    public function test_owner_cannot_edit_another_tenants_image_menu_item(): void
    {
        [$attacker, $rA] = $this->tenant();
        [, $rB] = $this->tenant();
        $img = ImageMenu::create([
            'name' => 'Board', 'image' => 'default.png', 'active' => '1', 'store_id' => $rB->id,
        ]);

        $this->actingAs($attacker)->post(route('stores.updateImageMenuItem', $rA->id), [
            'id' => $img->id, 'name' => 'HACKED',
        ]);

        $this->assertSame('Board', $img->fresh()->name, 'Cross-tenant image-menu item was overwritten.');
    }

    public function test_owner_cannot_delete_another_tenants_variant(): void
    {
        [$attacker, $rA, $mA] = $this->tenant();
        [, , $victimMenu] = $this->tenant();
        $variant = MenuVariant::create(['menu_id' => $victimMenu->id, 'price' => 5, 'options' => []]);

        $this->actingAs($attacker)->post(route('stores.menuDeleteVariant', [$rA->id, $mA->id]), [
            'id' => $variant->id,
        ]);

        $this->assertNotNull(MenuVariant::find($variant->id), 'Cross-tenant variant was deleted.');
    }

    public function test_owner_cannot_reorder_another_tenants_variant(): void
    {
        [$attacker, $rA, $mA] = $this->tenant();
        [, , $victimMenu] = $this->tenant();
        $variant = MenuVariant::create(['menu_id' => $victimMenu->id, 'price' => 5, 'options' => []]);
        $variant->position = 7;
        $variant->save();

        $this->actingAs($attacker)->post(route('stores.menuReorderVariant', [$rA->id, $mA->id]), [
            'position' => [$variant->id], // index 0 → would set position 0
        ]);

        $this->assertSame(7, (int) $variant->fresh()->position, 'Cross-tenant variant was reordered.');
    }

    public function test_owner_cannot_delete_another_tenants_extra(): void
    {
        [$attacker, $rA, $mA] = $this->tenant();
        [, $rB, $victimMenu] = $this->tenant();
        $extra = MenuExtra::create([
            'title' => 'Cheese', 'price' => 2, 'menu_id' => $victimMenu->id, 'store_id' => $rB->id,
        ]);

        $this->actingAs($attacker)->post(route('stores.menuDeleteExtra', [$rA->id, $mA->id]), [
            'id' => $extra->id,
        ]);

        $this->assertNotNull(MenuExtra::find($extra->id), 'Cross-tenant extra was deleted.');
    }

    public function test_owner_cannot_reorder_another_tenants_extra(): void
    {
        [$attacker, $rA, $mA] = $this->tenant();
        [, $rB, $victimMenu] = $this->tenant();
        $extra = MenuExtra::create([
            'title' => 'Cheese', 'price' => 2, 'menu_id' => $victimMenu->id, 'store_id' => $rB->id,
        ]);
        $extra->position = 7;
        $extra->save();

        $this->actingAs($attacker)->post(route('stores.menuReorderExtra', [$rA->id, $mA->id]), [
            'position' => [$extra->id],
        ]);

        $this->assertSame(7, (int) $extra->fresh()->position, 'Cross-tenant extra was reordered.');
    }

    public function test_owner_cannot_reorder_another_tenants_image_menu_item(): void
    {
        [$attacker, $rA] = $this->tenant();
        [, $rB] = $this->tenant();
        $img = ImageMenu::create([
            'name' => 'Board', 'image' => 'default.png', 'active' => '1', 'store_id' => $rB->id,
        ]);
        $img->position = 7;
        $img->save();

        $this->actingAs($attacker)->post(route('stores.reorderImageMenuItem', $rA->id), [
            'position' => [$img->id],
        ]);

        $this->assertSame(7, (int) $img->fresh()->position, 'Cross-tenant image-menu item was reordered.');
    }
}
