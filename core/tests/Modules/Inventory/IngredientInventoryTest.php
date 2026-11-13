<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\Ingredient;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Inventory\Models\StockMovement;
use Juwri\Core\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

class IngredientInventoryTest extends TestCase
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

    private function makePlan(): object
    {
        return TestPlans::create([
            'name' => 'Plan ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
            'status' => 1,
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'group_id' => (string) $this->makePlan()->id,
            'status' => 1, 'email_verified_at' => now(),
        ]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'Restaurant ' . Str::random(6);
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeIngredient(Store $r, array $attrs = []): Ingredient
    {
        return Ingredient::create(array_merge([
            'store_id' => $r->id, 'name' => 'Ing ' . Str::random(4),
            'unit' => 'kg', 'current_stock' => 10, 'par_level' => 2, 'cost_per_unit' => 5,
        ], $attrs));
    }

    /** recordMovement updates cached current_stock and writes a balanced ledger row */
    public function test_record_movement_updates_stock_and_ledger(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r, ['current_stock' => 10]);

        $m = InventoryService::recordMovement($ing->id, 'purchase', 5, ['unit_cost' => 8]);

        $this->assertNotNull($m);
        $this->assertEquals(15, (float) $ing->fresh()->current_stock);
        $this->assertEquals(15, (float) $m->balance_after);
        // purchase updates the cached unit cost
        $this->assertEquals(8, (float) $ing->fresh()->cost_per_unit);

        InventoryService::recordMovement($ing->id, 'sale', -3);
        $this->assertEquals(12, (float) $ing->fresh()->current_stock);
        $this->assertEquals(2, StockMovement::where('ingredient_id', $ing->id)->count());
    }

    /** store creates ingredient and an opening-stock ledger row */
    public function test_store_creates_ingredient_with_opening_movement(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('inventory.ingredients.store'), [
            'store_id' => $r->id, 'name' => 'Flour', 'unit' => 'kg',
            'current_stock' => 25, 'par_level' => 5, 'cost_per_unit' => 2,
        ])->assertRedirect();

        $ing = Ingredient::where('store_id', $r->id)->where('name', 'Flour')->first();
        $this->assertNotNull($ing);
        $this->assertEquals(25, (float) $ing->current_stock);
        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $ing->id, 'type' => 'adjustment', 'balance_after' => 25,
        ]);
    }

    /** adjust handles in / out / waste / set */
    public function test_adjust_directions(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r, ['current_stock' => 10]);

        $this->actingAs($owner)->post(route('inventory.ingredients.adjust', $ing->id), ['direction' => 'in', 'quantity' => 4])->assertRedirect();
        $this->assertEquals(14, (float) $ing->fresh()->current_stock);

        $this->actingAs($owner)->post(route('inventory.ingredients.adjust', $ing->id), ['direction' => 'out', 'quantity' => 2])->assertRedirect();
        $this->assertEquals(12, (float) $ing->fresh()->current_stock);

        $this->actingAs($owner)->post(route('inventory.ingredients.adjust', $ing->id), ['direction' => 'waste', 'quantity' => 1])->assertRedirect();
        $this->assertEquals(11, (float) $ing->fresh()->current_stock);

        $this->actingAs($owner)->post(route('inventory.ingredients.adjust', $ing->id), ['direction' => 'set', 'quantity' => 30])->assertRedirect();
        $this->assertEquals(30, (float) $ing->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', ['ingredient_id' => $ing->id, 'type' => 'count']);
    }

    /** update + destroy */
    public function test_update_and_destroy(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r);

        $this->actingAs($owner)->put(route('inventory.ingredients.update', $ing->id), [
            'name' => 'Renamed', 'unit' => 'g', 'par_level' => 9, 'cost_per_unit' => 3,
        ])->assertRedirect();
        $this->assertEquals('Renamed', $ing->fresh()->name);
        $this->assertEquals('g', $ing->fresh()->unit);

        $this->actingAs($owner)->delete(route('inventory.ingredients.destroy', $ing->id))->assertRedirect();
        $this->assertDatabaseMissing('ingredients', ['id' => $ing->id]);
    }

    /** setMode persists and the helper reads it back */
    public function test_set_mode_and_helper(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->assertEquals('simple', inventory_mode($r->id));

        $this->actingAs($owner)->post(route('inventory.setMode'), [
            'store_id' => $r->id, 'mode' => 'advanced',
        ])->assertRedirect();

        $this->assertSame('advanced', StoreSettings::legacy((int) $r->id, 'inventory_mode'));
        $this->assertEquals('advanced', inventory_mode($r->id));
    }

    /** Tenant isolation: a different owner cannot adjust or update */
    public function test_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r);

        $intruder = $this->makeUser();

        $this->actingAs($intruder)->post(route('inventory.ingredients.adjust', $ing->id), ['direction' => 'in', 'quantity' => 5])->assertNotFound();
        $this->actingAs($intruder)->put(route('inventory.ingredients.update', $ing->id), ['name' => 'x', 'unit' => 'kg'])->assertNotFound();
        $this->assertEquals(10, (float) $ing->fresh()->current_stock);
    }

    /** Advanced-mode order deduction is a safe no-op before recipes exist (Phase 2) */
    public function test_order_deduction_noop_without_recipe(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        // Should not throw and should record no movement (no RecipeItem yet)
        InventoryService::deductForOrderItem(12345, 2, 999);
        $this->assertEquals(0, StockMovement::count());
    }
}
