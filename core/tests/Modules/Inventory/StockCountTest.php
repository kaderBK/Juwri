<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\Ingredient;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Inventory\Models\StockCount;
use Juwri\Modules\Inventory\Models\StockCountItem;
use Juwri\Modules\Inventory\Models\StockMovement;
use Juwri\Core\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class StockCountTest extends TestCase
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
            'unit' => 'kg', 'current_stock' => 100, 'par_level' => 5, 'cost_per_unit' => 1,
        ], $a));
    }

    /** Starting a count snapshots current stock as theoretical for each ingredient */
    public function test_store_snapshots_ingredients(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $a = $this->makeIngredient($r, ['current_stock' => 100]);
        $b = $this->makeIngredient($r, ['current_stock' => 40]);

        $this->actingAs($owner)->post(route('inventory.counts.store'), ['store_id' => $r->id])->assertRedirect();

        $count = StockCount::where('store_id', $r->id)->first();
        $this->assertEquals('open', $count->status);
        $this->assertEquals(2, $count->items()->count());
        $this->assertEquals(100, (float) $count->items()->where('ingredient_id', $a->id)->first()->theoretical_qty);
        $this->assertEquals(40, (float) $count->items()->where('ingredient_id', $b->id)->first()->theoretical_qty);
    }

    /** Closing a count posts variance corrections so current_stock matches the physical count */
    public function test_close_reconciles_stock(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r, ['current_stock' => 100]);

        $count = StockCount::create(['store_id' => $r->id, 'status' => 'open', 'user_id' => $owner->id]);
        $item = StockCountItem::create([
            'stock_count_id' => $count->id, 'ingredient_id' => $ing->id,
            'theoretical_qty' => 100, 'counted_qty' => null,
        ]);

        // Physically counted 92 (8 short — waste/theft)
        $this->actingAs($owner)->put(route('inventory.counts.save', $count->id), [
            'counted' => [$item->id => 92],
        ])->assertRedirect();

        $this->actingAs($owner)->post(route('inventory.counts.close', $count->id))->assertRedirect();

        $this->assertEquals('closed', $count->fresh()->status);
        $this->assertEquals(92, (float) $ing->fresh()->current_stock);
        $this->assertEquals(-8, (float) $item->fresh()->variance);
        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $ing->id, 'type' => 'count', 'reference_type' => 'stock_count', 'reference_id' => $count->id,
        ]);
    }

    /** A line with no counted_qty is left untouched (no phantom correction) */
    public function test_uncounted_line_is_skipped(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r, ['current_stock' => 50]);
        $count = StockCount::create(['store_id' => $r->id, 'status' => 'open', 'user_id' => $owner->id]);
        StockCountItem::create(['stock_count_id' => $count->id, 'ingredient_id' => $ing->id, 'theoretical_qty' => 50, 'counted_qty' => null]);

        InventoryService::applyStockCount($count->fresh());

        $this->assertEquals(50, (float) $ing->fresh()->current_stock);
        $this->assertEquals(0, StockMovement::where('type', 'count')->count());
    }

    public function test_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $count = StockCount::create(['store_id' => $r->id, 'status' => 'open', 'user_id' => $owner->id]);

        $intruder = $this->makeUser();
        $this->actingAs($intruder)->post(route('inventory.counts.close', $count->id))->assertNotFound();
    }
}
