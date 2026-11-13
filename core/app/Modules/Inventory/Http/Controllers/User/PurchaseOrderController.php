<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Http\Controllers\User;

use App\Models\Ingredient;
use App\Services\InventoryService;
use App\Support\StoreClock;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Inventory\Http\Controllers\User\Concerns\InventoryScope;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Modules\Inventory\Services\Inventory\CogsService;
use Juwri\Modules\Inventory\Services\Inventory\InvoiceCaptureService;
use Juwri\Modules\Inventory\Services\Inventory\ReceivingService;

class PurchaseOrderController extends Controller
{
    use InventoryScope;

    private function ownedPo(int $id): PurchaseOrder
    {
        $po = PurchaseOrder::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($id);
        $this->assertBranchVisible($po->branch_id); // P6-224

        return $po;
    }

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id') ?: dashboard_current_store()?->id;

        if ($storeId && ! in_array((int) $storeId, $storeIds, true)) {
            abort(403);
        }

        $query = PurchaseOrder::whereIn('store_id', $storeIds)->with('supplier');
        $branchId = $this->pinnedBranchId();
        if ($storeId) {
            $query->where('store_id', (int) $storeId);
            $branchId = $this->branchFor((int) $storeId, $request->query('branch_id'));
        }
        if ($branchId) {
            $query->where('branch_id', $branchId); // P6-224
        }

        $orders = $query->orderByDesc('id')->get();
        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);
        $branches = $storeId && ! $this->pinnedBranchId()
            ? Branch::where('store_id', (int) $storeId)->orderBy('position')->get(['id', 'name'])
            : collect();
        $suppliers = $storeId
            ? Supplier::where('store_id', (int) $storeId)->where('active', true)->orderBy('name')->get()
            : collect();

        return view('owner.inventory.purchase-orders', compact('orders', 'stores', 'storeId', 'suppliers', 'branches', 'branchId'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        if (! in_array((int) $data['store_id'], $this->ownerRestaurantIds(), true)) {
            abort(403);
        }
        $branchId = $this->branchFor((int) $data['store_id'], $data['branch_id'] ?? null); // P6-224: deliver to this branch

        if (! empty($data['supplier_id'])) {
            Supplier::where('store_id', $data['store_id'])->findOrFail($data['supplier_id']);
        }

        $po = PurchaseOrder::create([
            'store_id' => $data['store_id'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'branch_id' => $branchId,
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('inventory.purchaseOrders.show', $po->id);
    }

    public function show(int $purchaseOrder)
    {
        $po = $this->ownedPo($purchaseOrder);
        $po->load('items.ingredient', 'supplier');

        $ingredients = Ingredient::where('store_id', $po->store_id)
            ->where('active', true)->orderBy('name')->get();

        return view('owner.inventory.purchase-order-show', compact('po', 'ingredients'));
    }

    public function addItem(Request $request, int $purchaseOrder)
    {
        $po = $this->ownedPo($purchaseOrder);
        abort_if($po->status !== 'draft', 422, ___('Only draft orders can be edited.'));

        $data = $request->validate([
            'ingredient_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $ingredient = Ingredient::where('store_id', $po->store_id)->findOrFail($data['ingredient_id']);

        $lineTotal = round((float) $data['quantity'] * (float) $data['unit_cost'], 2);

        PurchaseOrderItem::updateOrCreate(
            ['purchase_order_id' => $po->id, 'ingredient_id' => $ingredient->id],
            ['quantity' => $data['quantity'], 'unit_cost' => $data['unit_cost'], 'line_total' => $lineTotal]
        );

        $po->recalcTotal();

        return back()->with('success', ___('Line added.'));
    }

    public function removeItem(int $purchaseOrder, int $item)
    {
        $po = $this->ownedPo($purchaseOrder);
        abort_if($po->status !== 'draft', 422, ___('Only draft orders can be edited.'));

        PurchaseOrderItem::where('purchase_order_id', $po->id)->where('id', $item)->delete();
        $po->recalcTotal();

        return back()->with('success', ___('Line removed.'));
    }

    public function markOrdered(int $purchaseOrder)
    {
        $po = $this->ownedPo($purchaseOrder);
        abort_if($po->status !== 'draft', 422, ___('Only draft orders can be marked as ordered.'));
        abort_if($po->items()->count() < 1, 422, ___('Add at least one item before ordering.'));

        $po->update(['status' => 'ordered', 'ordered_at' => now()]);

        return back()->with('success', ___('Marked as ordered.'));
    }

    public function receive(int $purchaseOrder)
    {
        $po = $this->ownedPo($purchaseOrder);
        abort_if($po->status === 'cancelled', 422, ___('This order is cancelled.'));
        // The service reloads the PO under lockForUpdate and re-checks status inside the
        // transaction — defense in depth against a stale $po and against concurrent receives.
        InventoryService::receivePurchaseOrder($po);

        return back()->with('success', ___('Stock received and added to inventory.'));
    }

    /** SYSTEMS-RUN SUP-P1: line-level receiving with actual qty/price + discrepancies. */
    public function receiveLines(Request $request, int $purchaseOrder)
    {
        $po = $this->ownedPo($purchaseOrder);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.qty_received' => ['required', 'numeric', 'min:0'],
            'lines.*.actual_unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        // Guard the lines belong to this PO.
        $poItemIds = $po->items->pluck('id')->all();
        foreach ($data['lines'] as $line) {
            abort_unless(in_array((int) $line['item_id'], $poItemIds, true), 422);
        }
        $result = new ReceivingService()->receive($po, $data['lines']);

        return response()->json($result);
    }

    /** SYSTEMS-RUN SUP-P2: COGS / variance / reorder / spend report. */
    public function cogs(Request $request)
    {
        $ids = $this->ownerRestaurantIds();
        $rid = (int) ($request->query('store_id') ?: ($ids[0] ?? 0));
        abort_unless(in_array($rid, $ids, true), 403);

        $from = $request->query('from') ?: StoreClock::now($rid)->startOfMonth()->toDateString();
        $to = $request->query('to') ?: StoreClock::today($rid);
        $svc = new CogsService;
        $purchases = $svc->purchases($rid, $from, $to);
        $theoretical = (float) $request->query('theoretical', 0);

        return view('owner.inventory.cogs', [
            'rid' => $rid,
            'from' => $from,
            'to' => $to,
            'purchases' => $purchases,
            'variance' => $svc->variance($purchases, $theoretical, store_currency($rid)),
            'reorder' => $svc->reorderSuggestions($rid),
            'spend' => $svc->supplierSpend($rid, $from, $to),
            'stores' => Store::whereIn('id', $ids)->get(['id', 'title']),
        ]);
    }

    /** SYSTEMS-RUN SUP-P1: capture a supplier invoice (manual). */
    public function captureInvoice(Request $request, int $purchaseOrder)
    {
        $po = $this->ownedPo($purchaseOrder);
        $data = $request->validate([
            'invoice_no' => ['nullable', 'string', 'max:80'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['nullable', 'array'],
        ]);
        $invoice = new InvoiceCaptureService()->capture($po->store_id, [
            'supplier_id' => $po->supplier_id,
            'purchase_order_id' => $po->id,
            'invoice_no' => $data['invoice_no'] ?? null,
            'total' => $data['total'] ?? 0,
            'lines' => $data['lines'] ?? [],
        ]);
        $reconcile = new InvoiceCaptureService()->reconcile($po->fresh('items'), $invoice);

        return response()->json(['invoice_id' => $invoice->id, 'reconcile' => $reconcile]);
    }

    public function cancel(int $purchaseOrder)
    {
        $po = $this->ownedPo($purchaseOrder);
        if ($po->status !== 'received') {
            $po->update(['status' => 'cancelled']);
        }

        return back()->with('success', ___('Order cancelled.'));
    }
}
