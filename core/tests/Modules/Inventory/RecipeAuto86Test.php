<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\RecipeItem;
use App\Models\StoreOption;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D10 — recipe-depletion auto-86 (option `recipe_auto_86`, default OFF). A depleted
 * recipe ingredient 86s its dependent items; a restock un-86s exactly those; the
 * option OFF is today's behaviour; a manual sold-out is never touched.
 */
class RecipeAuto86Test extends TestCase
{
    use RefreshDatabase;

    private Store $r;

    private Ingredient $ing;

    private Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $this->r->id, 'name' => 'M', 'active' => 1]);
        $this->menu = Menu::create(['store_id' => $this->r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => 1]);
        $this->ing = Ingredient::create(['store_id' => $this->r->id, 'name' => 'Patty', 'unit' => 'ea', 'current_stock' => 5, 'par_level' => 0, 'cost_per_unit' => 1, 'position' => 1]);
        RecipeItem::create(['menu_id' => $this->menu->id, 'ingredient_id' => $this->ing->id, 'quantity' => 5]); // 5 patties per burger
    }

    private function enable(): void
    {
        StoreOption::updateStoreOption($this->r->id, 'recipe_auto_86', '1');
    }

    public function test_depletion_86s_dependent_items(): void
    {
        $this->enable();
        // One burger consumes all 5 patties → ingredient hits 0 → auto-86.
        InventoryService::deductForOrderItem($this->menu->id, 1);

        $this->menu->refresh();
        $this->assertTrue((bool) $this->menu->is_sold_out);
        $this->assertTrue((bool) $this->menu->auto_86_recipe);
    }

    public function test_restock_un_86s_the_recipe_driven_flag(): void
    {
        $this->enable();
        InventoryService::deductForOrderItem($this->menu->id, 1); // deplete → 86
        $this->assertTrue((bool) $this->menu->fresh()->is_sold_out);

        // Receive stock back → un-86.
        InventoryService::recordMovement($this->ing->id, 'adjustment', 10);

        $this->menu->refresh();
        $this->assertFalse((bool) $this->menu->is_sold_out);
        $this->assertFalse((bool) $this->menu->auto_86_recipe);
    }

    public function test_option_off_is_todays_behaviour(): void
    {
        // No recipe_auto_86 → depletion must NOT flip the item.
        InventoryService::deductForOrderItem($this->menu->id, 1);

        $this->menu->refresh();
        $this->assertFalse((bool) $this->menu->is_sold_out);
        $this->assertFalse((bool) $this->menu->auto_86_recipe);
    }

    public function test_manual_sold_out_is_never_touched(): void
    {
        $this->enable();
        // The owner manually 86s the item (auto_86_recipe stays false).
        $this->menu->update(['is_sold_out' => true, 'auto_86_recipe' => false]);

        // Ingredient depletes then restocks — the manual flag must survive both.
        InventoryService::deductForOrderItem($this->menu->id, 1);
        InventoryService::recordMovement($this->ing->id, 'adjustment', 10);

        $this->menu->refresh();
        $this->assertTrue((bool) $this->menu->is_sold_out); // manual 86 preserved
        $this->assertFalse((bool) $this->menu->auto_86_recipe);
    }

    public function test_toggle_endpoint_persists_the_option(): void
    {
        $owner = User::find($this->r->user_id);
        $this->withoutMiddleware()
            ->actingAs($owner)
            ->post(route('inventory.recipes.auto86'), ['store_id' => $this->r->id, 'recipe_auto_86' => '1'])
            ->assertRedirect();

        $this->assertSame('1', (string) StoreOption::getStoreOption($this->r->id, 'recipe_auto_86', '0'));
    }
}
