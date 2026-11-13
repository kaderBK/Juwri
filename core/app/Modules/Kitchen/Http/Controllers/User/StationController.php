<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Kitchen\Http\Controllers\User;

use App\Models\BranchMenuStation;
use App\Models\KitchenStation;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

class StationController extends Controller
{
    private function ownerRestaurantIds(): array
    {
        return Store::where('user_id', auth()->id())->pluck('id')->toArray();
    }

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id') ?: dashboard_current_store()?->id;

        if ($storeId && ! in_array((int) $storeId, $storeIds, true)) {
            abort(403);
        }

        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);

        $stations = collect();
        $menus = collect();
        $branches = collect();
        $branchId = (int) (dashboard_request_branch_id((int) $storeId ?: null) ?? 0);
        $pivot = collect();
        if ($storeId) {
            $branches = Branch::where('store_id', (int) $storeId)->orderBy('position')->get();
            if ($branchId && ! $branches->firstWhere('id', $branchId)) {
                abort(403);
            }
            $stations = KitchenStation::where('store_id', (int) $storeId)->orderBy('position')->get();
            $menus = Menu::where('store_id', (int) $storeId)->with('station')->orderBy('name')->get();
            if ($branchId) {
                $pivot = BranchMenuStation::where('branch_id', $branchId)->get()->keyBy('menu_id');
            }
        }

        return view('owner.kitchen.stations', compact(
            'stores', 'storeId', 'stations', 'menus', 'branches', 'branchId', 'pivot'
        ));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:80'],
            'display_color' => ['nullable', 'string', 'max:7'],
            'target_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'is_expo' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        if (! in_array((int) $data['store_id'], $this->ownerRestaurantIds(), true)) {
            abort(403);
        }
        $branchId = $this->resolveBranch($data['branch_id'] ?? null, (int) $data['store_id']);

        $next = (int) KitchenStation::where('store_id', $data['store_id'])->max('position');
        KitchenStation::create([
            'store_id' => $data['store_id'],
            'branch_id' => $branchId,
            'name' => $data['name'],
            'display_color' => ($data['display_color'] ?? '') ?: '#6366f1',
            'target_minutes' => $data['target_minutes'] ?? 10,
            'is_expo' => $request->boolean('is_expo'),
            'active' => true,
            'position' => $next + 1,
        ]);

        return back()->with('success', ___('Station added.'));
    }

    public function update(Request $request, int $station)
    {
        $model = KitchenStation::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($station);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'display_color' => ['nullable', 'string', 'max:7'],
            'active' => ['nullable', 'boolean'],
            'target_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'is_expo' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $model->update([
            'name' => $data['name'],
            'display_color' => ($data['display_color'] ?? '') ?: '#6366f1',
            'active' => $request->boolean('active'),
            'target_minutes' => $data['target_minutes'] ?? 10,
            'is_expo' => $request->boolean('is_expo'),
            'branch_id' => $this->resolveBranch($data['branch_id'] ?? null, $model->store_id),
        ]);

        return back()->with('success', ___('Station updated.'));
    }

    public function destroy(int $station)
    {
        $model = KitchenStation::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($station);

        Menu::where('station_id', $model->id)->update(['station_id' => null]);
        BranchMenuStation::where('station_id', $model->id)->delete();
        $model->delete();

        return back()->with('success', ___('Station deleted.'));
    }

    /**
     * Assign a menu item to a station. With a branch_id → per-branch override (pivot);
     * without → the menu's default station.
     */
    public function assign(Request $request)
    {
        $data = $request->validate([
            'menu_id' => ['required', 'integer'],
            'station_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $menu = Menu::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($data['menu_id']);

        $stationId = null;
        if (! empty($data['station_id'])) {
            $station = KitchenStation::where('store_id', $menu->store_id)->findOrFail($data['station_id']);
            $stationId = $station->id;
        }

        if (! empty($data['branch_id'])) {
            // Per-branch override.
            $branch = Branch::where('store_id', $menu->store_id)->findOrFail($data['branch_id']);
            if ($stationId) {
                BranchMenuStation::updateOrCreate(
                    ['branch_id' => $branch->id, 'menu_id' => $menu->id],
                    ['station_id' => $stationId]
                );
            } else {
                BranchMenuStation::where('branch_id', $branch->id)->where('menu_id', $menu->id)->delete();
            }
        } else {
            // Default (restaurant-wide) station.
            $menu->update(['station_id' => $stationId]);
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', ___('Item assigned.'));
    }

    /** Validate a branch belongs to the restaurant; null = restaurant-wide. */
    private function resolveBranch($branchId, int $storeId): ?int
    {
        if (empty($branchId)) {
            return null;
        }
        Branch::where('store_id', $storeId)->findOrFail($branchId);

        return (int) $branchId;
    }
}
