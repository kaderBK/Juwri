<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\Ingredient;
use App\Models\StoreOption;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\StockMovement;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Modules\Inventory\Models\SupplierPayment;
use Juwri\Modules\Inventory\Services\Inventory\CogsService;
use Juwri\Modules\Inventory\Services\Inventory\InvoiceCaptureService;
use Juwri\Modules\Inventory\Services\Inventory\ReceivingService;
use Juwri\Modules\Inventory\Services\Inventory\SupplierBalanceService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-05 slice 8a (characterisation): the public money behaviour of Inventory — ingredient unit cost and stock value,
 * stock-movement unit costs, waste cost and the 30-day waste sums, purchase-order lines/totals, line receiving
 * (weighted-average cost), supplier invoices, supplier payments / balance / statement, COGS purchases and supplier
 * spend; USD and a 3-decimal (OMR) store. Written against the decimal columns BEFORE the move to `*_minor` and kept
 * green after it: only the storage changes.
 */
class InventoryMoneyCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.currency_code' => 'USD']);
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        View::share('errors', new ViewErrorBag);
        $this->withoutMiddleware();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner, string $currency = 'USD'): Store
    {
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'i-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    private function ingredient(Store $store, array $attrs = []): Ingredient
    {
        // store_id first: the old StoreMoney cast resolved the store's decimals when the amount was SET.
        return Ingredient::create(array_merge(['store_id' => $store->id, 'name' => 'I'.Str::random(4), 'unit' => 'kg'], $attrs));
    }

    public function test_ingredient_cost_shapes_and_stock_value(): void
    {
        $store = $this->store($this->owner());
        $plain = $this->ingredient($store);
        $this->assertSame('0.00', $plain->fresh()->cost_per_unit, 'column default, store precision');

        $ing = $this->ingredient($store, ['current_stock' => 50, 'cost_per_unit' => 4]);
        $fresh = $ing->fresh();
        $this->assertSame('4.00', $fresh->cost_per_unit);
        $this->assertSame('4.00', $fresh->toArray()['cost_per_unit']);
        $this->assertSame(200.0, $fresh->stock_value);

        $fresh->update(['cost_per_unit' => 2.5]);
        $this->assertSame('2.50', $fresh->fresh()->cost_per_unit);

        // A 3-decimal store keeps its fils.
        $omr = $this->store($this->owner(), 'OMR');
        $o = $this->ingredient($omr, ['current_stock' => 10, 'cost_per_unit' => 0.275]);
        $this->assertSame('0.275', $o->fresh()->cost_per_unit);
        $this->assertSame(2.75, $o->fresh()->stock_value);
    }

    public function test_movements_waste_and_waste_reports(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $ing = $this->ingredient($store, ['current_stock' => 0, 'cost_per_unit' => 1]);

        $in = InventoryService::recordMovement($ing->id, 'purchase', 10, ['unit_cost' => 1.25]);
        $this->assertSame('1.25', $in->fresh()->unit_cost);
        $this->assertSame('1.25', $ing->fresh()->cost_per_unit, 'a purchase refreshes the unit cost');
        $adj = InventoryService::recordMovement($ing->id, 'adjustment', -1);
        $this->assertNull($adj->fresh()->unit_cost);
        $this->assertNull($adj->fresh()->toArray()['unit_cost']);

        $w1 = InventoryService::logWaste($ing->id, 3, 'spoilage');
        $this->assertSame('3.75', $w1->fresh()->cost);
        $this->assertSame('3.75', $w1->fresh()->toArray()['cost']);
        $w2 = InventoryService::logWaste($ing->id, 2, 'expiry');
        $this->assertSame('2.50', $w2->fresh()->cost);

        $page = $this->actingAs($owner)->get(route('inventory.waste.index', ['store_id' => $store->id]));
        $page->assertOk();
        $this->assertSame(6.25, $page->viewData('wasteCost30'));
        $byReason = $page->viewData('byReason')->keyBy('reason');
        $this->assertEqualsWithDelta(3.75, (float) $byReason['spoilage']->total_cost, 0.0001);
        $this->assertEqualsWithDelta(2.5, (float) $byReason['expiry']->total_cost, 0.0001);

        $report = $this->actingAs($owner)->get(route('inventory.reports.index', ['store_id' => $store->id]));
        $report->assertOk();
        $this->assertSame(6.25, $report->viewData('report')['wasteCost30']);
        $this->assertSame(5.0, $report->viewData('report')['stockValue'], '4 kg left × 1.25');
    }

    public function test_purchase_orders_receiving_invoices_and_supplier_balance(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $supplier = Supplier::create(['store_id' => $store->id, 'name' => 'Acme', 'active' => true]);
        $flour = $this->ingredient($store, ['current_stock' => 0, 'cost_per_unit' => 2]);
        $oil = $this->ingredient($store, ['current_stock' => 0, 'cost_per_unit' => 1]);
        $po = PurchaseOrder::create(['store_id' => $store->id, 'supplier_id' => $supplier->id, 'status' => 'draft']);
        $this->assertSame('0.00', $po->fresh()->total);

        $this->actingAs($owner)->post(route('inventory.purchaseOrders.addItem', $po->id), [
            'ingredient_id' => $flour->id, 'quantity' => 10, 'unit_cost' => 3,
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('inventory.purchaseOrders.addItem', $po->id), [
            'ingredient_id' => $oil->id, 'quantity' => 4, 'unit_cost' => 1.5,
        ])->assertRedirect();
        $this->assertSame('36.00', $po->fresh()->total);
        $line = PurchaseOrderItem::where('ingredient_id', $flour->id)->first();
        $this->assertSame('3.00', $line->unit_cost);
        $this->assertSame('30.00', $line->line_total);
        $this->assertNull($line->actual_unit_cost);
        $this->assertSame(['3.00', '30.00', null], [
            $line->toArray()['unit_cost'], $line->toArray()['line_total'], $line->toArray()['actual_unit_cost'],
        ]);

        // Line receiving: 10 flour at 3.20 (price discrepancy), 2 of 4 oil at the ordered cost.
        $oilLine = PurchaseOrderItem::where('ingredient_id', $oil->id)->first();
        $res = (new ReceivingService)->receive($po->fresh(), [
            ['item_id' => $line->id, 'qty_received' => 10, 'actual_unit_cost' => 3.2],
            ['item_id' => $oilLine->id, 'qty_received' => 2],
        ]);
        $this->assertSame('partial', $res['status']);
        $this->assertSame('3.20', $line->fresh()->actual_unit_cost);
        $this->assertSame('1.50', $oilLine->fresh()->actual_unit_cost);
        $this->assertSame('3.20', $flour->fresh()->cost_per_unit, 'weighted average from an empty stock');
        $this->assertSame('1.50', $oil->fresh()->cost_per_unit);
        $this->assertSame('3.20', StockMovement::where('ingredient_id', $flour->id)->where('type', 'purchase')->first()->unit_cost);

        $this->assertSame(35.0, SupplierBalanceService::chargesTotal($supplier));
        $this->assertSame(35.0, SupplierBalanceService::poReceivedValue($po->fresh()));

        $this->actingAs($owner)->post(route('inventory.suppliers.payment', $supplier->id), ['amount' => 20.5, 'method' => 'cash'])
            ->assertRedirect();
        $payment = SupplierPayment::where('supplier_id', $supplier->id)->first();
        $this->assertSame(20.5, $payment->amount);
        $this->assertSame(20.5, $payment->toArray()['amount']);
        SupplierPayment::create(['supplier_id' => $supplier->id, 'amount' => 4.25, 'paid_at' => now()->subMinute()]);
        $this->assertSame(24.75, SupplierBalanceService::paymentsTotal($supplier));
        $this->assertSame(10.25, SupplierBalanceService::balance($supplier));
        $amounts = array_column(SupplierBalanceService::statement($supplier), 'amount');
        sort($amounts);
        $this->assertSame([-20.5, -4.25, 35.0], $amounts);

        $invoice = (new InvoiceCaptureService)->capture($store->id, [
            'supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'lines' => [['name' => 'Flour', 'qty' => 10, 'unit_cost' => 3.2, 'total' => 32]],
        ]);
        $this->assertSame('32.00', $invoice->fresh()->total);
        $this->assertSame('32.00', $invoice->fresh()->toArray()['total']);
        $rec = (new InvoiceCaptureService)->reconcile($po->fresh('items'), $invoice->fresh());
        $this->assertSame(['ordered' => 36.0, 'received' => 35.0, 'invoiced' => 32.0, 'mismatch' => true], $rec);

        $cogs = new CogsService;
        $this->assertSame(35.0, $cogs->purchases($store->id));
        $this->assertSame([['supplier' => 'Acme', 'total' => 35.0]], $cogs->supplierSpend($store->id));
    }

    public function test_three_decimal_store_keeps_fils(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'OMR');
        $supplier = Supplier::create(['store_id' => $store->id, 'name' => 'Omani', 'active' => true]);
        $ing = $this->ingredient($store, ['current_stock' => 10, 'cost_per_unit' => 0.125]);
        $po = PurchaseOrder::create(['store_id' => $store->id, 'supplier_id' => $supplier->id, 'status' => 'draft']);
        $this->assertSame('0.000', $po->fresh()->total);

        $this->actingAs($owner)->post(route('inventory.purchaseOrders.addItem', $po->id), [
            'ingredient_id' => $ing->id, 'quantity' => 4, 'unit_cost' => 0.275,
        ])->assertRedirect();
        $line = PurchaseOrderItem::where('purchase_order_id', $po->id)->first();
        $this->assertSame('0.275', $line->unit_cost);
        $this->assertSame('1.100', $line->line_total);
        $this->assertSame('1.100', $po->fresh()->total);

        $waste = InventoryService::logWaste($ing->id, 3, 'spoilage');
        // P1-05 slice 10a-2: qty × cost rounds to the currency exponent (before: to 2 decimals → '0.380').
        $this->assertSame('0.375', $waste->fresh()->cost);

        $this->actingAs($owner)->post(route('inventory.suppliers.payment', $supplier->id), ['amount' => 12.375])->assertRedirect();
        $this->assertSame(12.375, SupplierPayment::where('supplier_id', $supplier->id)->first()->amount);
        // Bug 6: supplier totals round to the currency exponent (before: 12.38).
        $this->assertSame(12.375, SupplierBalanceService::paymentsTotal($supplier));

        // Bug 6: capture keeps the fils (before: '7.130').
        $invoice = (new InvoiceCaptureService)->capture($store->id, ['total' => 7.125]);
        $this->assertSame('7.125', $invoice->fresh()->total);

        (new ReceivingService)->receive($po->fresh(), [['item_id' => $line->id, 'qty_received' => 4]]);
        $this->assertSame(1.1, SupplierBalanceService::chargesTotal($supplier));
        $this->assertSame(-11.275, SupplierBalanceService::balance($supplier), 'before: -11.28');

        // Bug 6: reconcile at 3 decimals, tolerance one fil (before: 2 decimals, tolerance 0.01 — a 1.103 invoice
        // against 1.100 received rounded to 1.10 and was NOT flagged).
        $rec = (new InvoiceCaptureService)->reconcile($po->fresh('items'), $invoice->fresh());
        $this->assertSame([1.1, 1.1, 7.125, true], [$rec['ordered'], $rec['received'], $rec['invoiced'], $rec['mismatch']]);
        $oneFil = (new InvoiceCaptureService)->capture($store->id, ['total' => 1.101]);
        $this->assertFalse((new InvoiceCaptureService)->reconcile($po->fresh('items'), $oneFil->fresh())['mismatch']);
        $threeFils = (new InvoiceCaptureService)->capture($store->id, ['total' => 1.103]);
        $this->assertTrue((new InvoiceCaptureService)->reconcile($po->fresh('items'), $threeFils->fresh())['mismatch']);
    }
}
