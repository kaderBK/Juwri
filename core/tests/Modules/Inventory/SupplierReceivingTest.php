<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\Ingredient;
use Juwri\Core\Models\Store;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Core\Models\User;
use Juwri\Modules\Inventory\Services\Inventory\InvoiceCaptureService;
use Juwri\Modules\Inventory\Services\Inventory\ReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SYSTEMS-RUN SUP-P1: receiving (partial, discrepancies, idempotent stock) + OCR.
 */
class SupplierReceivingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_supplier_suite' => true]);
    }

    private function po(): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        $supplier = Supplier::create(['store_id' => $r->id, 'name' => 'S']);
        $ing = Ingredient::create(['store_id' => $r->id, 'name' => 'Flour', 'unit' => 'kg', 'current_stock' => 0, 'cost_per_unit' => 2]);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'supplier_id' => $supplier->id, 'status' => 'ordered', 'total' => 20]);
        $item = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $ing->id, 'quantity' => 10, 'unit_cost' => 2, 'line_total' => 20]);
        return [$owner, $r, $po, $item, $ing];
    }

    public function test_partial_receipt_and_discrepancies(): void
    {
        [$owner, $r, $po, $item, $ing] = $this->po();
        $svc = new ReceivingService();
        $res = $svc->receive($po->fresh('items'), [['item_id' => $item->id, 'qty_received' => 6, 'actual_unit_cost' => 2.5]]);

        $this->assertSame('partial', $res['status']);
        $this->assertEqualsWithDelta(6.0, (float) $ing->fresh()->current_stock, 0.01);
        // qty discrepancy (6 of 10) + price discrepancy (2.5 vs 2).
        $this->assertCount(2, $res['discrepancies']);
    }

    public function test_stock_update_is_idempotent_on_incremental_receive(): void
    {
        [$owner, $r, $po, $item, $ing] = $this->po();
        $svc = new ReceivingService();
        $svc->receive($po->fresh('items'), [['item_id' => $item->id, 'qty_received' => 6]]);
        // Re-confirm at 10 total → only +4 more, not +10.
        $res = $svc->receive($po->fresh('items'), [['item_id' => $item->id, 'qty_received' => 10]]);
        $this->assertEqualsWithDelta(10.0, (float) $ing->fresh()->current_stock, 0.01);
        $this->assertSame('received', $res['status']);
    }

    public function test_ocr_uses_fake_extractor(): void
    {
        [$owner, $r, $po] = $this->po();
        $fake = fn (string $path) => [['name' => 'Flour', 'qty' => 10, 'unit_cost' => 2, 'total' => 20]];
        $svc = new InvoiceCaptureService($fake);
        $lines = $svc->ocr('invoices/x.pdf');
        $this->assertSame('Flour', $lines[0]['name']);

        $invoice = $svc->capture($r->id, ['purchase_order_id' => $po->id, 'lines' => $lines, 'source' => 'ocr']);
        $this->assertEqualsWithDelta(20.0, (float) $invoice->total, 0.01);
    }

    public function test_reconcile_flags_mismatch(): void
    {
        [$owner, $r, $po, $item] = $this->po();
        new ReceivingService()->receive($po->fresh('items'), [['item_id' => $item->id, 'qty_received' => 10, 'actual_unit_cost' => 2]]);
        $svc = new InvoiceCaptureService();
        $invoice = $svc->capture($r->id, ['purchase_order_id' => $po->id, 'total' => 30]); // invoice 30 vs received 20
        $rec = $svc->reconcile($po->fresh('items'), $invoice);
        $this->assertTrue($rec['mismatch']);
        $this->assertEqualsWithDelta(20.0, $rec['received'], 0.01);
    }

    public function test_endpoint_tenant_scoped(): void
    {
        [$owner, $r, $po, $item] = $this->po();
        [$other, $otherR, $otherPo] = $this->po();
        $this->withoutMiddleware();
        $this->actingAs($owner);
        $this->postJson(route('inventory.purchaseOrders.receiveLines', $otherPo->id), [
            'lines' => [['item_id' => 1, 'qty_received' => 1]],
        ])->assertNotFound();
    }
}
