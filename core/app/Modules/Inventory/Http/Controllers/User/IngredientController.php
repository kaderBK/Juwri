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

class IngredientController extends Controller
{
    use InventoryScope;

    private function assertOwns(int $storeId): void
    {
        if (! in_array($storeId, $this->ownerRestaurantIds(), true)) {
            abort(403);
        }
    }

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id') ?: dashboard_current_store()?->id;

        if ($storeId && ! in_array((int) $storeId, $storeIds, true)) {
            abort(403);
        }

        $query = Ingredient::whereIn('store_id', $storeIds);
        if ($storeId) {
            $query->where('store_id', (int) $storeId);
        }

        $ingredients = $query->orderBy('position')->orderBy('name')->get();
        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);

        // #76: optional per-branch view — show that branch's on-hand instead of the shared stock.
        $branches = collect();
        $branchId = (int) $request->query('branch_id', 0);
        $branch = null;
        if ($storeId) {
            $branchId = (int) $this->branchFor((int) $storeId, $branchId); // P6-224: pinned staff see their branch only
            $branches = Branch::where('store_id', (int) $storeId)->orderBy('position')->get();
            if ($this->pinnedBranchId()) {
                $branches = $branches->where('id', $branchId)->values();
            }
            if ($branchId) {
                $branch = $branches->firstWhere('id', $branchId);
                $stocks = BranchStock::where('branch_id', $branch->id)
                    ->whereIn('ingredient_id', $ingredients->pluck('id'))->get()->keyBy('ingredient_id');
                // Override the displayed on-hand/par with the branch figures (in-memory).
                $ingredients->each(function ($ing) use ($stocks) {
                    $s = $stocks->get($ing->id);
                    $ing->current_stock = $s ? $s->current_stock : 0;
                    $ing->par_level = $s ? $s->par_level : $ing->par_level;
                });
            }
        }

        $lowStockCount = $ingredients->filter->is_low->count();
        $stockValue = round($ingredients->sum->stock_value, 2);

        return view('owner.inventory.ingredients', compact(
            'ingredients', 'stores', 'storeId', 'lowStockCount', 'stockValue', 'branches', 'branchId'
        ));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['required', 'in:'.implode(',', Ingredient::UNITS)],
            'par_level' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->assertOwns((int) $data['store_id']);

        $ingredient = Ingredient::create([
            'store_id' => $data['store_id'],
            'name' => $data['name'],
            'unit' => $data['unit'],
            'par_level' => $data['par_level'] ?? 0,
            'cost_per_unit' => $data['cost_per_unit'] ?? 0,
            'current_stock' => 0,
        ]);

        // Opening stock becomes the first ledger movement (auditable).
        $opening = (float) ($data['current_stock'] ?? 0);
        if ($opening > 0) {
            InventoryService::recordMovement($ingredient->id, 'adjustment', $opening, [
                'unit_cost' => $data['cost_per_unit'] ?? null,
                'reason' => ___('Opening stock'),
            ]);
        }

        return back()->with('success', ___('Ingredient added.'));
    }

    public function update(Request $request, int $ingredient)
    {
        $model = Ingredient::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($ingredient);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['required', 'in:'.implode(',', Ingredient::UNITS)],
            'par_level' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ]);

        $model->update([
            'name' => $data['name'],
            'unit' => $data['unit'],
            'par_level' => $data['par_level'] ?? 0,
            'cost_per_unit' => $data['cost_per_unit'] ?? 0,
            'active' => $request->boolean('active'),
        ]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', ___('Ingredient updated.'));
    }

    public function destroy(int $ingredient)
    {
        $model = Ingredient::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($ingredient);
        $model->delete();

        return back()->with('success', ___('Ingredient deleted.'));
    }

    /**
     * Manual stock change: receive (+), use (-), or waste (-).
     */
    public function adjust(Request $request, int $ingredient)
    {
        $model = Ingredient::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($ingredient);

        $data = $request->validate([
            'direction' => ['required', 'in:in,out,waste,set'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $qty = (float) $data['quantity'];

        // #76: optional branch scope — the adjustment hits that branch's stock row (P6-224: pinned staff only theirs).
        $branchId = $this->branchFor((int) $model->store_id, $data['branch_id'] ?? null);

        // Current on-hand for the 'set' delta — branch row if scoped, else shared.
        $currentOnHand = (float) $model->current_stock;
        if ($branchId) {
            $bs = BranchStock::where('branch_id', $branchId)->where('ingredient_id', $model->id)->first();
            $currentOnHand = $bs ? (float) $bs->current_stock : 0.0;
        }

        switch ($data['direction']) {
            case 'in':
                InventoryService::recordMovement($model->id, 'purchase', $qty, [
                    'branch_id' => $branchId,
                    'unit_cost' => $data['unit_cost'] ?? null,
                    'reason' => $data['reason'] ?? ___('Stock received'),
                ]);
                break;
            case 'out':
                InventoryService::recordMovement($model->id, 'adjustment', -$qty, [
                    'branch_id' => $branchId,
                    'reason' => $data['reason'] ?? ___('Manual usage'),
                ]);
                break;
            case 'waste':
                InventoryService::recordMovement($model->id, 'waste', -$qty, [
                    'branch_id' => $branchId,
                    'reason' => $data['reason'] ?? ___('Waste'),
                ]);
                break;
            case 'set':
                // Correct on-hand to an exact figure (a mini stock-count).
                $delta = round($qty - $currentOnHand, 3);
                if ($delta != 0.0) {
                    InventoryService::recordMovement($model->id, 'count', $delta, [
                        'branch_id' => $branchId,
                        'reason' => $data['reason'] ?? ___('Stock correction'),
                    ]);
                }
                break;
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'current_stock' => $model->fresh()->current_stock]);
        }

        return back()->with('success', ___('Stock updated.'));
    }

    public function history(int $ingredient)
    {
        $model = Ingredient::whereIn('store_id', $this->ownerRestaurantIds())
            ->with(['movements' => fn ($q) => $q->limit(100)])
            ->findOrFail($ingredient);

        return view('owner.inventory.history', ['ingredient' => $model]);
    }
}
