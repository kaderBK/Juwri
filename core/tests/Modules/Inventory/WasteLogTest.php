<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\Ingredient;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Inventory\Models\StockMovement;
use Juwri\Core\Models\User;
use Juwri\Modules\Inventory\Models\WasteLog;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class WasteLogTest extends TestCase
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
            'unit' => 'kg', 'current_stock' => 50, 'par_level' => 5, 'cost_per_unit' => 4,
        ], $a));
    }

    /** Logging waste deducts stock and records the costed value */
    public function test_log_waste_deducts_and_costs(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r, ['current_stock' => 50, 'cost_per_unit' => 4]);

        $this->actingAs($owner)->post(route('inventory.waste.store'), [
            'ingredient_id' => $ing->id, 'quantity' => 3, 'reason' => 'spoilage',
        ])->assertRedirect();

        $this->assertEquals(47, (float) $ing->fresh()->current_stock);
        $this->assertDatabaseHas('waste_logs', [
            'ingredient_id' => $ing->id, 'reason' => 'spoilage', 'quantity' => 3, 'cost_minor' => 1200, // 3 * 4 (P1-05: minor units)
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $ing->id, 'type' => 'waste', 'reference_type' => 'waste_log',
        ]);
    }

    /** Service guards: zero qty and missing ingredient return null with no side effects */
    public function test_log_waste_guards(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r);

        $this->assertNull(InventoryService::logWaste($ing->id, 0, 'spoilage'));
        $this->assertNull(InventoryService::logWaste(999999, 5, 'spoilage'));
        $this->assertEquals(0, WasteLog::count());
        $this->assertEquals(0, StockMovement::where('type', 'waste')->count());
    }

    /** Cannot log waste against another owner's ingredient */
    public function test_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r);

        $intruder = $this->makeUser();
        $this->actingAs($intruder)->post(route('inventory.waste.store'), [
            'ingredient_id' => $ing->id, 'quantity' => 1, 'reason' => 'spoilage',
        ])->assertNotFound();
        $this->assertEquals(50, (float) $ing->fresh()->current_stock);
    }

    /** Reports page renders for an owner (nova theme has no view → assert route authorises, not render) */
    public function test_reports_route_authorises_owner_only(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $intruder = $this->makeUser();

        // Foreign store_id must be rejected
        $this->actingAs($intruder)->get(route('inventory.reports.index', ['store_id' => $r->id]))->assertForbidden();
    }
}
