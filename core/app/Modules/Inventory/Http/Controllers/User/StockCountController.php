<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Http\Controllers\User;

use App\Models\Ingredient;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Inventory\Http\Controllers\User\Concerns\InventoryScope;
use Juwri\Modules\Inventory\Models\BranchStock;
use Juwri\Modules\Inventory\Models\StockCount;
use Juwri\Modules\Inventory\Models\StockCountItem;

class StockCountController extends Controller
{
    use InventoryScope;

    private function ownedCount(int $id): StockCount
    {
        $count = StockCount::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($id);
        $this->assertBranchVisible($count->branch_id); // P6-224

        return $count;
    }

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id') ?: dashboard_current_store()?->id;

        if ($storeId && ! in_array((int) $storeId, $storeIds, true)) {
            abort(403);
        }

        $query = StockCount::whereIn('store_id', $storeIds)->withCount('items');
        $branchId = $this->pinnedBranchId();
        if ($storeId) {
            $query->where('store_id', (int) $storeId);
            $branchId = $this->branchFor((int) $storeId, $request->query('branch_id'));
        }
        if ($branchId) {
            $query->where('branch_id', $branchId); // P6-224
        }

        $counts = $query->orderByDesc('id')->get();
        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);
        $branches = $storeId && ! $this->pinnedBranchId()
            ? Branch::where('store_id', (int) $storeId)->orderBy('position')->get(['id', 'name'])
            : collect();

        return view('owner.inventory.counts', compact('counts', 'stores', 'storeId', 'branches', 'branchId'));
    }

    /** Start a count: snapshot every active ingredient's current (theoretical) stock. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        if (! in_array((int) $data['store_id'], $this->ownerRestaurantIds(), true)) {
            abort(403);
        }
        $branchId = $this->branchFor((int) $data['store_id'], $data['branch_id'] ?? null); // P6-224

        $count = StockCount::create([
            'store_id' => $data['store_id'],
            'branch_id' => $branchId,
            'status' => 'open',
            'notes' => $data['notes'] ?? null,
            'user_id' => auth()->id(),
        ]);

        $ingredients = Ingredient::where('store_id', $data['store_id'])->where('active', true)->get();
        // P6-224: a branch count starts from that branch's balance, not the store-wide figure
        $branchStock = $branchId
            ? BranchStock::where('branch_id', $branchId)->whereIn('ingredient_id', $ingredients->pluck('id'))->pluck('current_stock', 'ingredient_id')
            : null;
        foreach ($ingredients as $ing) {
            StockCountItem::create([
                'stock_count_id' => $count->id,
                'ingredient_id' => $ing->id,
                'theoretical_qty' => $branchStock ? ($branchStock[$ing->id] ?? 0) : $ing->current_stock,
                'counted_qty' => null,
            ]);
        }

        return redirect()->route('inventory.counts.show', $count->id);
    }

    public function show(int $stockCount)
    {
        $count = $this->ownedCount($stockCount);
        $count->load('items.ingredient');

        return view('owner.inventory.count-show', compact('count'));
    }

    /** Save entered counted quantities (does not close the count). */
    public function saveCounts(Request $request, int $stockCount)
    {
        $count = $this->ownedCount($stockCount);
        abort_if($count->status === 'closed', 422, ___('This count is already closed.'));

        $counted = $request->input('counted', []); // [item_id => qty]
        foreach ($counted as $itemId => $qty) {
            if ($qty === '' || $qty === null) {
                continue;
            }
            StockCountItem::where('stock_count_id', $count->id)->where('id', (int) $itemId)
                ->update(['counted_qty' => (float) $qty]);
        }

        return back()->with('success', ___('Counts saved.'));
    }

    /** Close the count → post variance corrections to the ledger. */
    public function close(int $stockCount)
    {
        $count = $this->ownedCount($stockCount);
        InventoryService::applyStockCount($count);

        return redirect()->route('inventory.counts.show', $count->id)
            ->with('success', ___('Count closed. Stock corrected to match.'));
    }
}
