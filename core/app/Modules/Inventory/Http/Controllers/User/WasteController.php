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
use Juwri\Modules\Inventory\Models\WasteLog;

class WasteController extends Controller
{
    use InventoryScope;

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id') ?: dashboard_current_store()?->id;

        if ($storeId && ! in_array((int) $storeId, $storeIds, true)) {
            abort(403);
        }

        $scopeIds = $storeId ? [(int) $storeId] : $storeIds;
        // P6-224: one branch's waste when a branch is chosen (always the pinned one for branch staff)
        $branchId = $storeId ? $this->branchFor((int) $storeId, $request->query('branch_id')) : $this->pinnedBranchId();
        $inBranch = fn ($q) => $branchId ? $q->where('branch_id', $branchId) : $q;

        $logs = WasteLog::whereIn('store_id', $scopeIds)->tap($inBranch)
            ->with('ingredient')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        // Costed waste by reason for the last 30 days.
        $since = now()->subDays(30);
        $byReason = WasteLog::whereIn('store_id', $scopeIds)->tap($inBranch)
            ->where('created_at', '>=', $since)
            ->selectRaw('reason, SUM('.WasteLog::minorMoneySql('cost').') as total_cost, SUM(quantity) as total_qty')
            ->groupBy('reason')
            ->get();
        $wasteCost30 = round((float) $byReason->sum('total_cost'), 2);

        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);
        $ingredients = $storeId
            ? Ingredient::where('store_id', (int) $storeId)->where('active', true)->orderBy('name')->get()
            : collect();

        $branches = $storeId && ! $this->pinnedBranchId()
            ? Branch::where('store_id', (int) $storeId)->orderBy('position')->get(['id', 'name'])
            : collect();

        return view('owner.inventory.waste', compact(
            'logs', 'byReason', 'wasteCost30', 'stores', 'storeId', 'ingredients', 'branches', 'branchId'
        ));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'ingredient_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'reason' => ['required', 'in:'.implode(',', WasteLog::REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        // Ingredient must belong to one of the owner's restaurants.
        $ingredient = Ingredient::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($data['ingredient_id']);
        $branchId = $this->branchFor((int) $ingredient->store_id, $data['branch_id'] ?? null); // P6-224

        InventoryService::logWaste(
            (int) $data['ingredient_id'],
            (float) $data['quantity'],
            $data['reason'],
            $data['note'] ?? null,
            $branchId
        );

        return back()->with('success', ___('Waste logged.'));
    }
}
