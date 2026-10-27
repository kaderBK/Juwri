<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\RecipeItem;
use App\Models\StoreOption;
use App\Services\InventoryService;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Inventory\Models\StockMovement;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class RecipeInventoryTest extends TestCase
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
            'name' => 'Plan '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeIngredient(Store $r, array $a = []): Ingredient
    {
        return Ingredient::create(array_merge([
            'store_id' => $r->id, 'name' => 'Ing '.Str::random(4),
            'unit' => 'g', 'current_stock' => 1000, 'par_level' => 100, 'cost_per_unit' => 0.05,
        ], $a));
    }

    private function makeMenuItem(Store $r, array $a = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Cat '.Str::random(4)]);

        return Menu::create(array_merge([
            'store_id' => $r->id, 'category_id' => $cat->id,
            'name' => 'Pizza', 'price' => 10.00, 'active' => '1',
        ], $a));
    }

    /** recipe_cost and food_cost_percent accessors */
    public function test_recipe_cost_and_food_cost_percent(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($r, ['price' => 10.00]);
        $cheese = $this->makeIngredient($r, ['cost_per_unit' => 0.02]); // per g
        $dough = $this->makeIngredient($r, ['cost_per_unit' => 0.01]);

        RecipeItem::create(['menu_id' => $item->id, 'ingredient_id' => $cheese->id, 'quantity' => 80]);  // 80*0.02 = 1.60
        RecipeItem::create(['menu_id' => $item->id, 'ingredient_id' => $dough->id, 'quantity' => 200]);  // 200*0.01 = 2.00

        $item->load('recipeItems.ingredient');
        $this->assertEquals(3.60, $item->recipe_cost);
        $this->assertEquals(36.0, $item->food_cost_percent); // 3.60/10 * 100
    }

    /** Selling deducts every recipe ingredient and writes sale movements */
    public function test_order_deduction_consumes_recipe(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($r);
        $cheese = $this->makeIngredient($r, ['current_stock' => 1000]);
        $dough = $this->makeIngredient($r, ['current_stock' => 1000]);

        RecipeItem::create(['menu_id' => $item->id, 'ingredient_id' => $cheese->id, 'quantity' => 80]);
        RecipeItem::create(['menu_id' => $item->id, 'ingredient_id' => $dough->id, 'quantity' => 200]);

        // Sell 3 pizzas
        InventoryService::deductForOrderItem($item->id, 3, 555);

        $this->assertEquals(1000 - (80 * 3), (float) $cheese->fresh()->current_stock); // 760
        $this->assertEquals(1000 - (200 * 3), (float) $dough->fresh()->current_stock);  // 400
        $this->assertEquals(2, StockMovement::where('type', 'sale')->count());
        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $cheese->id, 'type' => 'sale', 'reference_type' => 'order', 'reference_id' => 555,
        ]);
    }

    /** Reversing a deduction restores every recipe ingredient (void/refund/cancel path). */
    public function test_reverse_for_order_item_restores_recipe_ingredients(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($r);
        $cheese = $this->makeIngredient($r, ['current_stock' => 1000]);
        $dough = $this->makeIngredient($r, ['current_stock' => 1000]);

        RecipeItem::create(['menu_id' => $item->id, 'ingredient_id' => $cheese->id, 'quantity' => 80]);
        RecipeItem::create(['menu_id' => $item->id, 'ingredient_id' => $dough->id, 'quantity' => 200]);

        InventoryService::deductForOrderItem($item->id, 3, 555);   // cheese 760, dough 400
        $this->assertEquals(760, (float) $cheese->fresh()->current_stock);

        InventoryService::reverseForOrderItem($item->id, 3, 555);  // back to 1000
        $this->assertEquals(1000, (float) $cheese->fresh()->current_stock);
        $this->assertEquals(1000, (float) $dough->fresh()->current_stock);
        $this->assertEquals(2, StockMovement::where('type', 'adjustment')->count());
    }

    /**
     * POS "Edit order" (replaceItems) in ADVANCED mode must reverse the OUTGOING lines'
     * ingredients before re-deducting the new set. It used to call Menu::restoreStock
     * directly — a no-op for recipe items — so every edit leaked ingredient stock (old
     * lines never reversed, new lines deducted afresh).
     */
    public function test_replace_items_reverses_ingredients_in_advanced_mode(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        StoreOption::updateStoreOption($r->id, 'inventory_mode', 'advanced');
        $item = $this->makeMenuItem($r);
        $cheese = $this->makeIngredient($r, ['current_stock' => 1000]);
        RecipeItem::create(['menu_id' => $item->id, 'ingredient_id' => $cheese->id, 'quantity' => 80]);

        // Place an order for 3 → deducts 3 × 80 = 240 → 760.
        $order = OrderApiService::create((int) $r->user_id, (int) $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 3]],
        ]);
        $this->assertEquals(760.0, (float) $cheese->fresh()->current_stock, 'order deducted the recipe');

        // Edit the order (re-save the same 3): old lines reversed (+240) then new
        // deducted (−240) → still 760. Pre-fix the raw restore was a no-op, leaving 520.
        OrderApiService::replaceItems($order->fresh(), [['item_id' => $item->id, 'quantity' => 3]]);

        $this->assertEquals(760.0, (float) $cheese->fresh()->current_stock, 'edit is stock-symmetric — no ingredient leak');
    }

    /** RecipeController store upserts a line; destroy removes it; tenant isolation enforced */
    public function test_recipe_crud_and_isolation(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($r);
        $ing = $this->makeIngredient($r);

        $this->actingAs($owner)->post(route('inventory.recipes.store', $item->id), [
            'ingredient_id' => $ing->id, 'quantity' => 50,
        ])->assertRedirect();
        $this->assertDatabaseHas('recipe_items', ['menu_id' => $item->id, 'ingredient_id' => $ing->id, 'quantity' => 50]);

        // Upsert (same pair updates quantity, not duplicate)
        $this->actingAs($owner)->post(route('inventory.recipes.store', $item->id), [
            'ingredient_id' => $ing->id, 'quantity' => 75,
        ])->assertRedirect();
        $this->assertEquals(1, RecipeItem::where('menu_id', $item->id)->count());
        $this->assertEquals(75, (float) RecipeItem::where('menu_id', $item->id)->first()->quantity);

        // Intruder cannot edit
        $intruder = $this->makeUser();
        $this->actingAs($intruder)->post(route('inventory.recipes.store', $item->id), [
            'ingredient_id' => $ing->id, 'quantity' => 1,
        ])->assertNotFound();

        // Destroy
        $this->actingAs($owner)->delete(route('inventory.recipes.destroy', [$item->id, $ing->id]))->assertRedirect();
        $this->assertDatabaseMissing('recipe_items', ['menu_id' => $item->id, 'ingredient_id' => $ing->id]);
    }

    /** Cannot attach an ingredient from another restaurant */
    public function test_cannot_attach_foreign_ingredient(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $r2 = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($r1);
        $foreign = $this->makeIngredient($r2);

        $this->actingAs($owner)->post(route('inventory.recipes.store', $item->id), [
            'ingredient_id' => $foreign->id, 'quantity' => 10,
        ])->assertNotFound();
        $this->assertDatabaseMissing('recipe_items', ['menu_id' => $item->id, 'ingredient_id' => $foreign->id]);
    }
}
