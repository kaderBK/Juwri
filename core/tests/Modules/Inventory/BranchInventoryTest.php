<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use Juwri\Core\Models\Branch;
use Juwri\Modules\Inventory\Models\BranchStock;
use App\Models\Ingredient;
use Juwri\Core\Models\Language;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Core\Models\Store;
use App\Models\RecipeItem;
use Juwri\Modules\Inventory\Models\StockMovement;
use Juwri\Core\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class BranchInventoryTest extends TestCase
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

    private function makeIngredient(Store $r, array $a = []): Ingredient
    {
        return Ingredient::create(array_merge([
            'store_id' => $r->id, 'name' => 'Ing ' . Str::random(4),
            'unit' => 'g', 'current_stock' => 100, 'par_level' => 5, 'cost_per_unit' => 1,
        ], $a));
    }

    /** A branch-scoped movement updates branch_stocks, not the shared current_stock */
    public function test_branch_movement_isolated_from_shared(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $ing = $this->makeIngredient($r, ['current_stock' => 100]);

        InventoryService::recordMovement($ing->id, 'purchase', 40, ['branch_id' => $branch->id, 'unit_cost' => 2]);

        // Shared stock untouched; branch stock = 40
        $this->assertEquals(100, (float) $ing->fresh()->current_stock);
        $bs = BranchStock::where('branch_id', $branch->id)->where('ingredient_id', $ing->id)->first();
        $this->assertEquals(40, (float) $bs->current_stock);
        // cost is shared and was refreshed
        $this->assertEquals(2, (float) $ing->fresh()->cost_per_unit);
        $this->assertDatabaseHas('stock_movements', ['ingredient_id' => $ing->id, 'branch_id' => $branch->id, 'type' => 'purchase']);
    }

    /** Without a branch, behaviour is unchanged (shared stock) */
    public function test_shared_movement_unchanged(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r, ['current_stock' => 50]);

        InventoryService::recordMovement($ing->id, 'sale', -10);
        $this->assertEquals(40, (float) $ing->fresh()->current_stock);
        $this->assertEquals(0, BranchStock::count());
    }

    /** Sale deduction draws from the order's branch stock */
    public function test_order_deduction_hits_branch_stock(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 10, 'active' => '1']);
        $cheese = $this->makeIngredient($r, ['current_stock' => 100]);
        RecipeItem::create(['menu_id' => $item->id, 'ingredient_id' => $cheese->id, 'quantity' => 30]);

        // Seed branch stock then sell 2 at that branch
        BranchStock::create(['branch_id' => $branch->id, 'ingredient_id' => $cheese->id, 'current_stock' => 100, 'par_level' => 0]);
        InventoryService::deductForOrderItem($item->id, 2, 777, $branch->id);

        $bs = BranchStock::where('branch_id', $branch->id)->where('ingredient_id', $cheese->id)->first();
        $this->assertEquals(40, (float) $bs->current_stock); // 100 - 60
        $this->assertEquals(100, (float) $cheese->fresh()->current_stock); // shared untouched
    }

    /** adjust endpoint with branch_id scopes to the branch */
    public function test_adjust_endpoint_branch_scoped(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $ing = $this->makeIngredient($r, ['current_stock' => 10]);

        $this->actingAs($owner)->post(route('inventory.ingredients.adjust', $ing->id), [
            'direction' => 'in', 'quantity' => 7, 'branch_id' => $branch->id,
        ])->assertRedirect();

        $bs = BranchStock::where('branch_id', $branch->id)->where('ingredient_id', $ing->id)->first();
        $this->assertEquals(7, (float) $bs->current_stock);
        $this->assertEquals(10, (float) $ing->fresh()->current_stock);
    }

    /** Cannot adjust against a branch from another restaurant */
    public function test_adjust_rejects_foreign_branch(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $r2 = $this->makeRestaurant($owner);
        $foreign = Branch::where('store_id', $r2->id)->first();
        $ing = $this->makeIngredient($r1);

        $this->actingAs($owner)->post(route('inventory.ingredients.adjust', $ing->id), [
            'direction' => 'in', 'quantity' => 5, 'branch_id' => $foreign->id,
        ])->assertNotFound();
    }
}
