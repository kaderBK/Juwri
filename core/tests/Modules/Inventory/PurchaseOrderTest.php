<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\Ingredient;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\StockMovement;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Core\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
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
            'unit' => 'kg', 'current_stock' => 0, 'par_level' => 5, 'cost_per_unit' => 0,
        ], $a));
    }

    public function test_supplier_crud_and_isolation(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('inventory.suppliers.store'), [
            'store_id' => $r->id, 'name' => 'ACME Foods', 'lead_time_days' => 3,
        ])->assertRedirect();
        $sup = Supplier::where('store_id', $r->id)->first();
        $this->assertEquals('ACME Foods', $sup->name);

        $intruder = $this->makeUser();
        $this->actingAs($intruder)->put(route('inventory.suppliers.update', $sup->id), ['name' => 'Hacked', 'unit' => 'kg'])->assertNotFound();
        $this->assertEquals('ACME Foods', $sup->fresh()->name);
    }

    public function test_po_flow_create_add_receive_adds_stock(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $sup = Supplier::create(['store_id' => $r->id, 'name' => 'ACME', 'active' => true]);
        $ing = $this->makeIngredient($r, ['current_stock' => 10, 'cost_per_unit' => 2]);

        // Create draft PO
        $this->actingAs($owner)->post(route('inventory.purchaseOrders.store'), [
            'store_id' => $r->id, 'supplier_id' => $sup->id,
        ])->assertRedirect();
        $po = PurchaseOrder::where('store_id', $r->id)->first();
        $this->assertEquals('draft', $po->status);

        // Add a line: 50 kg @ 3.00 = 150
        $this->actingAs($owner)->post(route('inventory.purchaseOrders.addItem', $po->id), [
            'ingredient_id' => $ing->id, 'quantity' => 50, 'unit_cost' => 3,
        ])->assertRedirect();
        $this->assertEquals(150, (float) $po->fresh()->total);

        // Receive → stock +50 (10→60), cost refreshed to 3, movement recorded, PO received
        $this->actingAs($owner)->post(route('inventory.purchaseOrders.receive', $po->id))->assertRedirect();
        $this->assertEquals(60, (float) $ing->fresh()->current_stock);
        $this->assertEquals(3, (float) $ing->fresh()->cost_per_unit);
        $this->assertEquals('received', $po->fresh()->status);
        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $ing->id, 'type' => 'purchase', 'reference_type' => 'purchase_order', 'reference_id' => $po->id,
        ]);
    }

    public function test_receive_is_idempotent(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r, ['current_stock' => 0]);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'status' => 'draft', 'user_id' => $owner->id]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $ing->id, 'quantity' => 20, 'unit_cost' => 1, 'line_total' => 20]);

        InventoryService::receivePurchaseOrder($po->fresh());
        InventoryService::receivePurchaseOrder($po->fresh()); // second call must be a no-op

        $this->assertEquals(20, (float) $ing->fresh()->current_stock);
        $this->assertEquals(1, StockMovement::where('type', 'purchase')->count());
    }

    public function test_cannot_add_line_to_received_po(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'status' => 'received', 'user_id' => $owner->id]);

        $this->actingAs($owner)->post(route('inventory.purchaseOrders.addItem', $po->id), [
            'ingredient_id' => $ing->id, 'quantity' => 5, 'unit_cost' => 1,
        ])->assertStatus(422);
    }

    /** P6-281: receiving a cancelled PO must 422 and add NO stock movement. */
    public function test_cancelled_po_receive_returns_422_and_no_movement(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r, ['current_stock' => 10, 'cost_per_unit' => 1]);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'status' => 'cancelled', 'user_id' => $owner->id]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $ing->id, 'quantity' => 20, 'unit_cost' => 1, 'line_total' => 20]);

        $this->actingAs($owner)->post(route('inventory.purchaseOrders.receive', $po->id))
            ->assertStatus(422);

        // No stock movement written; ingredient untouched.
        $this->assertEquals(10, (float) $ing->fresh()->current_stock);
        $this->assertEquals(0, StockMovement::where('reference_type', 'purchase_order')->where('reference_id', $po->id)->count());
        $this->assertEquals('cancelled', $po->fresh()->status);
    }

    /** P6-281: two sequential receives with the same (stale) model produce exactly one stock movement. */
    public function test_two_receives_with_stale_model_one_movement(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r, ['current_stock' => 0]);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'status' => 'draft', 'user_id' => $owner->id]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $ing->id, 'quantity' => 20, 'unit_cost' => 1, 'line_total' => 20]);

        // Hold the original in-memory $po as a stale reference: first call moves DB to 'received',
        // but $po->status is still 'draft' because the model was never refreshed — this mirrors a
        // controller that loaded the PO before another caller completed the receive.
        InventoryService::receivePurchaseOrder($po);
        $this->assertEquals('received', $po->fresh()->status); // sanity: DB did move
        $this->assertEquals('draft', $po->status); // stale in-memory copy

        // Second call with the SAME stale model must see the locked fresh status and no-op.
        InventoryService::receivePurchaseOrder($po);

        $this->assertEquals(20, (float) $ing->fresh()->current_stock);
        $this->assertEquals(1, StockMovement::where('type', 'purchase')->where('reference_type', 'purchase_order')->where('reference_id', $po->id)->count());
    }

    /** P6-282: adding a line to a cancelled PO must 422 (only draft is editable). */
    public function test_add_line_on_cancelled_po_returns_422(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'status' => 'cancelled', 'user_id' => $owner->id]);

        $this->actingAs($owner)->post(route('inventory.purchaseOrders.addItem', $po->id), [
            'ingredient_id' => $ing->id, 'quantity' => 5, 'unit_cost' => 1,
        ])->assertStatus(422);

        $this->assertEquals(0, PurchaseOrderItem::where('purchase_order_id', $po->id)->count());
    }

    /** P6-282: removing a line from a cancelled PO must 422 (only draft is editable). */
    public function test_remove_line_on_cancelled_po_returns_422(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'status' => 'cancelled', 'user_id' => $owner->id]);
        $item = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $ing->id, 'quantity' => 5, 'unit_cost' => 1, 'line_total' => 5]);

        $this->actingAs($owner)->delete(route('inventory.purchaseOrders.removeItem', [$po->id, $item->id]))
            ->assertStatus(422);

        $this->assertEquals(1, PurchaseOrderItem::where('purchase_order_id', $po->id)->count());
    }

    /** P6-282: quantity 0 must 422 (validation: min:0.001). */
    public function test_add_line_quantity_zero_returns_422(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $ing = $this->makeIngredient($r);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'status' => 'draft', 'user_id' => $owner->id]);

        $this->actingAs($owner)->postJson(route('inventory.purchaseOrders.addItem', $po->id), [
            'ingredient_id' => $ing->id, 'quantity' => 0, 'unit_cost' => 1,
        ])->assertStatus(422);

        $this->assertEquals(0, PurchaseOrderItem::where('purchase_order_id', $po->id)->count());
    }

    /** P6-282: marking a PO with no lines as ordered must 422. */
    public function test_mark_ordered_no_lines_returns_422(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'status' => 'draft', 'user_id' => $owner->id]);

        $this->actingAs($owner)->post(route('inventory.purchaseOrders.ordered', $po->id))
            ->assertStatus(422);

        $this->assertEquals('draft', $po->fresh()->status);
    }
}
