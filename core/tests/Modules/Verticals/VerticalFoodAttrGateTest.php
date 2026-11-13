<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

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
 * rev/multi-vertical F0-4 — the food-attribute capability gate.
 *
 * veg/nonveg ("type") is required only for food verticals. A non-food store must
 * add an item without it (and without food fields); a restaurant must still be
 * forced to supply it.
 */
class VerticalFoodAttrGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeStore(User $owner, string $type): Store
    {
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_non_food_store_can_add_item_without_type(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'retail');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        $this->actingAs($owner)->postJson(route('stores.addMenuItem', $store), [
            'category_id' => $cat->id, 'name' => 'USB Cable', 'price' => 12.50,
        ])->assertOk()->assertJson(['success' => true]);

        $item = Menu::where('store_id', $store->id)->where('name', 'USB Cable')->first();
        $this->assertNotNull($item);
        $this->assertSame('veg', $item->type); // harmless default, never rendered for non-food
    }

    public function test_restaurant_still_requires_type(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'restaurant');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        $this->actingAs($owner)->postJson(route('stores.addMenuItem', $store), [
            'category_id' => $cat->id, 'name' => 'Burger', 'price' => 20,
        ])->assertOk()->assertJson(['success' => false]);

        $this->assertNull(Menu::where('store_id', $store->id)->where('name', 'Burger')->first());
    }
}
