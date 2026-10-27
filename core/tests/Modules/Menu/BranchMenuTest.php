<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Juwri\Core\Models\Branch;
use Juwri\Modules\Menu\Models\BranchMenu;
use Juwri\Core\Models\Language;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class BranchMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R ' . Str::random(6);
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeMenuItem(Store $r, float $price = 10): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Cat']);
        return Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => $price, 'active' => '1']);
    }

    /** No branch row → base price + available */
    public function test_defaults_to_base_price_and_available(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $item = $this->makeMenuItem($r, 12.50);

        $this->assertEquals(12.50, $item->priceForBranch($branch->id));
        $this->assertTrue($item->availableAtBranch($branch->id));
    }

    /** Price override + availability flag resolve per branch */
    public function test_override_price_and_availability(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $item = $this->makeMenuItem($r, 10);

        $this->actingAs($owner)->post(route('branches.menu.update', $branch->id), [
            'menu_id' => $item->id, 'price' => 13.00, 'available' => '1',
        ])->assertRedirect();
        $this->assertEquals(13.00, $item->fresh()->priceForBranch($branch->id));
        $this->assertTrue($item->fresh()->availableAtBranch($branch->id));

        // Turn it off at this branch, clear price → back to base
        $this->actingAs($owner)->post(route('branches.menu.update', $branch->id), [
            'menu_id' => $item->id, 'price' => '', 'available' => '0',
        ])->assertRedirect();
        $item->refresh();
        $this->assertEquals(10.00, $item->priceForBranch($branch->id));
        $this->assertFalse($item->availableAtBranch($branch->id));
    }

    /** Cannot set a branch price for an item from another restaurant */
    public function test_cannot_set_foreign_item(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $r2 = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r1->id)->first();
        $foreign = $this->makeMenuItem($r2);

        $this->actingAs($owner)->post(route('branches.menu.update', $branch->id), [
            'menu_id' => $foreign->id, 'price' => 5,
        ])->assertNotFound();
        $this->assertDatabaseMissing('branch_menu', ['branch_id' => $branch->id, 'menu_id' => $foreign->id]);
    }

    /** Tenant isolation on the branch itself */
    public function test_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $item = $this->makeMenuItem($r);

        $intruder = $this->makeUser();
        $this->actingAs($intruder)->post(route('branches.menu.update', $branch->id), [
            'menu_id' => $item->id, 'price' => 1,
        ])->assertNotFound();
    }
}
