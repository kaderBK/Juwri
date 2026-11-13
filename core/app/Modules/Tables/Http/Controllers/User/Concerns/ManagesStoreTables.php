<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Http\Controllers\User\Concerns;

use App\Models\StoreTable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;

/**
 * The owner's Tables & QR screen and its save/delete actions (routes bind by
 * method name inside Route::controller(StoreController)).
 *
 * The screen and the save are behind the `table_qr` feature; delete is not,
 * so an owner whose plan lost the feature can still clean up. A table id from
 * the body must belong to the route's store: an unknown id is a 404, another
 * store's table gets the generic error.
 */
trait ManagesStoreTables
{
    /**
     * Same branch scoping as the Floor Map (shared ScopesToBranch rule: legacy
     * branch_id = NULL rows belong to the default branch), so both screens
     * always list the same tables. `?branch=` picks another branch of the store.
     */
    public function tables(Store $store): View
    {
        abort_unless(plan_feature('table_qr', request()->user()), 404); // P6-2: resolver, not the plan blob
        abort_unless($this->ownsStore(request(), $store), 404);

        $branches = Branch::where('store_id', $store->id)->orderBy('id')->get(['id', 'name']);
        $branchId = null;
        if ($branches->isNotEmpty()) {
            $requested = request()->integer('branch');
            abort_if($requested && ! $branches->contains('id', $requested), 404);
            $branchId = $requested ?: (int) $branches->first()->id;
        }

        return view('owner.tables.index', [
            'store' => $store,
            'tables' => $this->scopeToBranch($store->tables()->orderBy('table_no'), $store->id, $branchId)->get(),
            'branches' => $branches,
            'branchId' => $branchId,
        ]);
    }

    /** Without an `id`: add a table (on the branch being viewed). With one: renumber it. */
    public function updateTable(Request $request, Store $store): RedirectResponse
    {
        abort_unless(plan_feature('table_qr', $request->user()), 404);
        if (! $this->ownsStore($request, $store)) {
            return $this->tableActionRefused();
        }
        $check = Validator::make($request->all(), ['table_number' => 'required|integer|max:255']);
        if ($check->fails()) {
            return $this->tableActionRefused($this->validationSummary($check));
        }

        $number = $request->input('table_number');
        if (empty($request->input('id'))) {
            StoreTable::create([
                'store_id' => $store->id,
                'branch_id' => $this->storeBranchOrNull($store, $request->integer('branch_id')),
                'table_no' => $number,
                'key' => uniqid(),
            ]);

            return $this->tableActionDone('Saved Successfully');
        }

        $table = StoreTable::findOrFail($request->input('id'));
        if ((int) $table->store_id !== (int) $store->id) {
            return $this->tableActionRefused();
        }
        $table->update(['table_no' => $number]);

        return $this->tableActionDone('Saved Successfully');
    }

    public function deleteTable(Request $request, Store $store): RedirectResponse
    {
        if (! $this->ownsStore($request, $store)) {
            return $this->tableActionRefused();
        }
        $check = Validator::make($request->all(), ['id' => 'required|integer']);
        if ($check->fails()) {
            return $this->tableActionRefused($this->validationSummary($check));
        }

        $table = StoreTable::findOrFail($request->input('id'));
        if ((int) $table->store_id !== (int) $store->id) {
            return $this->tableActionRefused();
        }
        $table->delete();

        return $this->tableActionDone('Deleted Successfully');
    }

    /** The branch id when it is one of the store's branches, else null (no branch). */
    private function storeBranchOrNull(Store $store, int $branchId): ?int
    {
        if ($branchId === 0) {
            return null;
        }

        return Branch::whereKey($branchId)->where('store_id', $store->id)->exists() ? $branchId : null;
    }

    private function tableActionDone(string $messageKey): RedirectResponse
    {
        app_alert_success(___($messageKey));

        return back();
    }

    /** Flash the reason (default: the generic error) and go back. */
    private function tableActionRefused(?string $reason = null): RedirectResponse
    {
        app_alert_error($reason ?? ___('Unexpected Error'));

        return back();
    }
}
