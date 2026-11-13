<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Kitchen\Http\Controllers\User;

use App\Support\StoreClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Kitchen\Models\FoodSafetyCheck;
use Juwri\Modules\Kitchen\Models\FoodSafetyLog;
use Juwri\Modules\Kitchen\Services\FoodSafety\FoodSafetyService;

/**
 * GAPS-RUN-2 B10-P2: owner food-safety page — configure checks, review logs
 * (breaches highlighted), incidents, compliance summary; plus a printable expiry
 * label sheet. Tenant scoped; behind the food_safety kill-switch.
 */
class FoodSafetyController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(private FoodSafetyService $safety) {}

    private function ownedCheck(int $id, array $ids): FoodSafetyCheck
    {
        return FoodSafetyCheck::whereIn('store_id', $ids)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        return view('owner.food-safety.index', [
            'rid' => $rid,
            'checks' => FoodSafetyCheck::where('store_id', $rid)->orderBy('position')->get(),
            'logs' => FoodSafetyLog::where('store_id', $rid)->latest('logged_at')->limit(60)->get(),
            'compliance' => $this->safety->complianceRate($rid, 30),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function storeCheck(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'in:temperature,appliance,cleaning'],
            'target_min' => ['nullable', 'numeric'],
            'target_max' => ['nullable', 'numeric'],
            'frequency' => ['required', 'in:daily,shift'],
        ]);

        FoodSafetyCheck::create(array_merge($data, [
            'store_id' => $rid,
            'position' => (int) FoodSafetyCheck::where('store_id', $rid)->max('position') + 1,
            'active' => true,
        ]));

        return back()->with('success', ___('Check added.'));
    }

    public function toggleCheck(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $check = $this->ownedCheck($id, $this->storeIds());
        $check->update(['active' => ! $check->active]);

        return back()->with('success', ___('Check updated.'));
    }

    public function destroyCheck(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $this->ownedCheck($id, $this->storeIds())->delete();

        return back()->with('success', ___('Check removed.'));
    }

    /** Printable expiry-label sheet. Owner enters item + prep date + shelf-life days. */
    public function labels(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $items = array_filter(array_map('trim', explode("\n", (string) $request->query('items'))));
        $shelfLife = max(0, (int) $request->query('days', 3));
        $prep = $request->query('prep') ?: StoreClock::today($rid);

        return view('owner.food-safety.labels', [
            'items' => $items,
            'prep' => $prep,
            'expiry' => Carbon::parse($prep)->addDays($shelfLife)->toDateString(),
            'shelfLife' => $shelfLife,
        ]);
    }
}
