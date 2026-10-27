<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\Ingredient;
use Juwri\Core\Models\Store;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Modules\Inventory\Models\SupplierPayment;
use Juwri\Core\Models\User;
use Juwri\Modules\Inventory\Services\Inventory\SupplierBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS E7 — supplier balance (received-goods value − payments) + statement.
 */
class SupplierBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 999], 'status' => 1]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1]);
    }

    private function store(User $owner): Store
    {
        $t = 'S'.Str::random(5);
        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t, 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1']);
    }

    private function supplier(Store $store): Supplier
    {
        return Supplier::create(['store_id' => $store->id, 'name' => 'Acme', 'active' => true]);
    }

    private function receivedPo(Store $store, Supplier $s, float $orderedQty, float $receivedQty, float $unitCost): PurchaseOrder
    {
        $po = PurchaseOrder::create(['store_id' => $store->id, 'supplier_id' => $s->id, 'status' => 'received', 'received_at' => now()]);
        $ing = Ingredient::create(['store_id' => $store->id, 'name' => 'I'.Str::random(4)]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'ingredient_id' => $ing->id,
            'quantity' => $orderedQty, 'unit_cost' => $unitCost, 'line_total' => $orderedQty * $unitCost,
            'qty_received' => $receivedQty, 'actual_unit_cost' => $unitCost,
        ]);
        return $po;
    }

    public function test_charges_reflect_partial_receiving(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $s = $this->supplier($store);
        // Ordered 10 @ 2, only 6 received => 12, not 20.
        $this->receivedPo($store, $s, 10, 6, 2);

        $this->assertSame(12.0, SupplierBalanceService::chargesTotal($s));
        $this->assertSame(12.0, SupplierBalanceService::balance($s));
    }

    public function test_payments_reduce_the_balance(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $s = $this->supplier($store);
        $this->receivedPo($store, $s, 10, 10, 3); // 30 received

        SupplierPayment::create(['supplier_id' => $s->id, 'amount' => 12, 'method' => 'cash', 'paid_at' => now()]);

        $this->assertSame(30.0, SupplierBalanceService::chargesTotal($s));
        $this->assertSame(12.0, SupplierBalanceService::paymentsTotal($s));
        $this->assertSame(18.0, SupplierBalanceService::balance($s));
    }

    public function test_statement_interleaves_charges_and_payments(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $s = $this->supplier($store);
        $this->receivedPo($store, $s, 5, 5, 4); // 20
        SupplierPayment::create(['supplier_id' => $s->id, 'amount' => 7, 'method' => 'bank', 'paid_at' => now()]);

        $rows = SupplierBalanceService::statement($s);
        $types = array_column($rows, 'type');
        $this->assertContains('charge', $types);
        $this->assertContains('payment', $types);

        $payment = collect($rows)->firstWhere('type', 'payment');
        $this->assertSame(-7.0, (float) $payment['amount'], 'payment shows as a credit');
    }

    public function test_show_page_renders(): void
    {
        $this->withoutMiddleware();
        $owner = $this->owner();
        $store = $this->store($owner);
        $s = $this->supplier($store);
        $this->receivedPo($store, $s, 4, 4, 5); // 20

        $html = $this->actingAs($owner)->get(route('inventory.suppliers.show', $s->id))->assertOk()->getContent();
        $this->assertStringContainsString('Outstanding balance', $html);
    }

    public function test_record_payment_endpoint(): void
    {
        $this->withoutMiddleware();
        $owner = $this->owner();
        $store = $this->store($owner);
        $s = $this->supplier($store);

        $this->actingAs($owner)->post(route('inventory.suppliers.payment', $s->id), [
            'amount' => 25, 'method' => 'cash', 'note' => 'first',
        ])->assertRedirect();

        $this->assertDatabaseHas('supplier_payments', ['supplier_id' => $s->id, 'method' => 'cash', 'note' => 'first']);
        $this->assertSame(25.0, SupplierBalanceService::paymentsTotal($s->fresh()));
    }

    public function test_cross_owner_supplier_is_404(): void
    {
        $this->withoutMiddleware();
        $owner = $this->owner();
        $foreign = $this->supplier($this->store($this->owner()));

        $this->actingAs($owner)->get(route('inventory.suppliers.show', $foreign->id))->assertNotFound();
        $this->actingAs($owner)->post(route('inventory.suppliers.payment', $foreign->id), ['amount' => 5])->assertNotFound();
    }
}
