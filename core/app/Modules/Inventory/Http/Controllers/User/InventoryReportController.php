<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Http\Controllers\User;

use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Inventory\Http\Controllers\User\Concerns\InventoryScope;
use Juwri\Modules\Inventory\Models\BranchStock;
use Juwri\Modules\Inventory\Models\StockCount;
use Juwri\Modules\Inventory\Models\WasteLog;
use Juwri\Modules\Menu\Models\Menu;

class InventoryReportController extends Controller
{
    use InventoryScope;

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id') ?: dashboard_current_store()?->id;

        if ($storeId && ! in_array((int) $storeId, $storeIds, true)) {
            abort(403);
        }

        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);

        // D9: optional per-branch view. Branches for the picker + the chosen id.
        $branches = collect();
        $branchId = (int) $request->query('branch_id', 0);

        $report = null;
        if ($storeId) {
            $rid = (int) $storeId;

            $branchId = (int) $this->branchFor($rid, $branchId); // P6-224: pinned staff see their branch only
            $branches = Branch::where('store_id', $rid)->orderBy('position')->get(['id', 'name']);
            if ($this->pinnedBranchId()) {
                $branches = $branches->where('id', $branchId)->values();
            }

            // Stock value on hand.
            $ingredients = Ingredient::where('store_id', $rid)->get();

            // D9: when a branch is chosen, show THAT branch's on-hand/par (BranchStock)
            // instead of the shared figures — mirrors IngredientController::index. A
            // branch with no BranchStock rows reads as empty; no branch = all (shared).
            if ($branchId && $branches->firstWhere('id', $branchId)) {
                $stocks = BranchStock::where('branch_id', $branchId)
                    ->whereIn('ingredient_id', $ingredients->pluck('id'))->get()->keyBy('ingredient_id');
                $ingredients->each(function ($ing) use ($stocks) {
                    $s = $stocks->get($ing->id);
                    $ing->current_stock = $s ? $s->current_stock : 0;
                    $ing->par_level = $s ? $s->par_level : $ing->par_level;
                });
            }

            $stockValue = round($ingredients->sum->stock_value, 2);
            $lowStock = $ingredients->filter->is_low->values();

            // Recipe / food-cost per menu item (only items that have a recipe).
            $menus = Menu::where('store_id', $rid)->with('recipeItems.ingredient')->orderBy('name')->get()
                ->filter(fn ($m) => $m->recipeItems->isNotEmpty())
                ->map(function ($m) {
                    return (object) [
                        'name' => $m->getRawOriginal('name'),
                        'price' => (float) $m->price,
                        'cost' => $m->recipe_cost,
                        'food_cost' => $m->food_cost_percent,
                    ];
                })->values();
            $avgFoodCost = $menus->whereNotNull('food_cost')->avg('food_cost');

            // Waste cost (last 30 days).
            $wasteCost30 = round((float) WasteLog::where('store_id', $rid)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->where('created_at', '>=', now()->subDays(30))->sum(DB::raw(WasteLog::minorMoneySql('cost'))), 2);

            // Variance from the most recent closed count (theoretical vs actual).
            $lastCount = StockCount::where('store_id', $rid)->where('status', 'closed')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->with('items.ingredient')->orderByDesc('counted_at')->first();
            $varianceValue = 0;
            if ($lastCount) {
                foreach ($lastCount->items as $it) {
                    $varianceValue += (float) $it->variance * (float) ($it->ingredient->cost_per_unit ?? 0);
                }
            }

            $report = compact('stockValue', 'lowStock', 'menus', 'avgFoodCost', 'wasteCost30', 'lastCount', 'varianceValue');
        }

        return view('owner.inventory.reports', compact('stores', 'storeId', 'report', 'branches', 'branchId'));
    }
}
