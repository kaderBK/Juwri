<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Http\Controllers\User;

use App\Models\StoreOption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

class InventoryController extends Controller
{
    private function ownerRestaurantIds(): array
    {
        return dashboard_store_ids(); // P6-224: a staff session is bound to its own store
    }

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id') ?: dashboard_current_store()?->id;

        if ($storeId && ! in_array((int) $storeId, $storeIds)) {
            abort(403);
        }

        $query = Menu::whereIn('store_id', $storeIds)->where('track_inventory', true);
        if ($storeId) {
            $query->where('store_id', (int) $storeId);
        }

        $items = $query->orderBy('stock_qty')->get();
        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);
        // P6-300: in-memory equivalent of Menu::scopeLowStock (tracked AND stock <= threshold; the
        // column is NOT NULL). Same rule as the digest query so the badge and the e-mail agree.
        $lowStockCount = $items->filter(fn ($i) => ($i->stock_qty ?? 0) <= (int) $i->low_stock_threshold)->count();

        // Inventory mode for the selected restaurant (mode switch only shows when one is picked).
        $mode = $storeId ? inventory_mode((int) $storeId) : null;

        // H5: how many orders were taken for stock the row did not have (oversell).
        $oversellCount = $storeId ? (int) (store_options((int) $storeId, 'stock_oversell_count', 0) ?: 0) : 0;
        $oversellAt = $storeId ? store_options((int) $storeId, 'stock_oversell_last_at') : null;

        return view('owner.inventory.stock', compact('items', 'stores', 'storeId', 'lowStockCount', 'mode', 'oversellCount', 'oversellAt'));
    }

    /**
     * H5: clear the per-store oversell counter once the merchant has restocked /
     * un-86'd the affected items. Mirrors setMode()'s ownership check.
     */
    public function resetOversell(Request $request): RedirectResponse
    {
        $data = $request->validate(['store_id' => ['required', 'integer']]);

        if (! in_array((int) $data['store_id'], $this->ownerRestaurantIds(), true)) {
            abort(403);
        }

        StoreOption::updateStoreOption($data['store_id'], 'stock_oversell_count', 0);

        return back()->with('success', ___('Oversell counter cleared.'));
    }

    public function update(Request $request, int $menuId)
    {
        $storeIds = $this->ownerRestaurantIds();
        $item = Menu::whereIn('store_id', $storeIds)->findOrFail($menuId);

        $data = $request->validate([
            'stock_qty' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'track_inventory' => 'nullable|boolean',
        ]);

        // If restocking, un-mark as sold out
        if (isset($data['stock_qty']) && $data['stock_qty'] > 0 && $item->is_sold_out) {
            $data['is_sold_out'] = false;
        }

        $item->update($data);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'is_sold_out' => (bool) $item->fresh()->is_sold_out]);
        }

        return back()->with('success', ___('Stock updated for :item.', ['item' => $item->name]));
    }

    /**
     * Switch a restaurant between 'simple' (per-item) and 'advanced' (ingredient-level) inventory.
     */
    public function setMode(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'mode' => ['required', 'in:simple,advanced'],
        ]);

        if (! in_array((int) $data['store_id'], $this->ownerRestaurantIds(), true)) {
            abort(403);
        }

        StoreOption::updateStoreOption($data['store_id'], 'inventory_mode', $data['mode']);

        return back()->with('success', ___('Inventory mode updated.'));
    }

    /**
     * Enable tracking on ALL items for a restaurant (bulk enable).
     */
    public function enableAll(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = (int) $request->input('store_id');

        if (! in_array($storeId, $storeIds)) {
            abort(403);
        }

        Menu::where('store_id', $storeId)->update(['track_inventory' => true]);

        return back()->with('success', ___('Inventory tracking enabled for all items.'));
    }
}
