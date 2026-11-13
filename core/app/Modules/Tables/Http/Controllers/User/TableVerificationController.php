<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Http\Controllers\User;

use App\Http\Controllers\User\StoreController;
use App\Models\StoreTable;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Tables\Services\TableSessionService;

/**
 * PARITY-RUN T3 — owner settings for verified table QR (Settings → Tables).
 *
 * The mode picker, the per-table code column with its regenerate buttons, and
 * the printable A6 tent-card sheet. Unlike the guest endpoints these routes DO
 * carry `feature:table_otp` — the caller is the authenticated merchant, which is
 * exactly the subject that middleware resolves, and a kill-switched platform
 * should hide the whole screen rather than serve a control that cannot work.
 */
class TableVerificationController extends Controller
{
    /** Resolve an owned restaurant, mirroring StoreController@tables. */
    private function resolveStore(Request $request, $storeId): Store
    {
        $store = $storeId instanceof Store ? $storeId : Store::findOrFail($storeId);

        abort_unless($store->user_id == $request->user()->id, 404);

        return $store;
    }

    /** Branch scoping identical to the Tables screen and the Floor Map. */
    private function tablesFor(Store $store, ?int $branchId)
    {
        $query = StoreTable::where('store_id', $store->id)
            ->where(function ($q) {
                // Hotel rooms/zones have their own QR sheet and their own gate.
                $q->whereNull('area_type')->orWhere('area_type', '!=', 'room');
            })
            ->orderBy('table_no');

        if ($branchId) {
            // Legacy rows carry a NULL branch and belong to the default branch.
            $query->where(function ($q) use ($branchId, $store) {
                $q->where('branch_id', $branchId);
                $firstBranchId = (int) Branch::where('store_id', $store->id)->orderBy('id')->value('id');
                if ($firstBranchId === $branchId) {
                    $q->orWhereNull('branch_id');
                }
            });
        }

        return $query->get();
    }

    private function branchContext(Request $request, Store $store): array
    {
        $branches = Branch::where('store_id', $store->id)->orderBy('id')->get(['id', 'name']);
        $branchId = null;

        if ($branches->isNotEmpty()) {
            $requested = $request->integer('branch');
            if ($requested) {
                abort_unless($branches->contains('id', $requested), 404);
                $branchId = $requested;
            } else {
                $branchId = (int) $branches->first()->id;
            }
        }

        return [$branches, $branchId];
    }

    public function index(Request $request, $store)
    {
        $store = $this->resolveStore($request, $store);
        [$branches, $branchId] = $this->branchContext($request, $store);

        $tables = $this->tablesFor($store, $branchId);
        $mode = TableSessionService::rawMode($store->id);

        // Only materialise codes once a code-based mode is actually chosen — a
        // store on `off` or `staff_approve` keeps a clean, code-free grid.
        if ($mode === TableSessionService::MODE_TABLE_CODE) {
            foreach ($tables as $table) {
                TableSessionService::codeFor($table);
            }
        }

        return view('owner.tables.verification', [
            'store' => $store,
            'tables' => $tables,
            'branches' => $branches,
            'branchId' => $branchId,
            'mode' => $mode,
            'effectiveMode' => TableSessionService::mode($store->id),
        ]);
    }

    public function saveMode(Request $request, $store)
    {
        $store = $this->resolveStore($request, $store);

        $mode = TableSessionService::setMode((int) $store->id, (string) $request->input('mode'));

        // Switching INTO table_code with no codes yet would show every guest a
        // gate they cannot pass, so seed them in the same action.
        if ($mode === TableSessionService::MODE_TABLE_CODE) {
            foreach ($this->tablesFor($store, null) as $table) {
                TableSessionService::codeFor($table);
            }
        }

        log_activity('table_verify.mode', $store, ['mode' => $mode], $store->id);
        app_alert_success(___('Saved Successfully'));

        return back();
    }

    public function rotate(Request $request, $store)
    {
        $store = $this->resolveStore($request, $store);

        $table = StoreTable::where('store_id', $store->id)
            ->where('id', $request->integer('table_id'))
            ->first();

        if (! $table) {
            app_alert_error(___('We could not find that table.'));

            return back();
        }

        TableSessionService::rotateCode($table);
        log_activity('table_verify.rotate', $store, ['table_id' => (int) $table->id], $store->id);
        app_alert_success(___('New table code generated.'));

        return back();
    }

    public function rotateAll(Request $request, $store)
    {
        $store = $this->resolveStore($request, $store);
        [, $branchId] = $this->branchContext($request, $store);

        $n = 0;
        foreach ($this->tablesFor($store, $branchId) as $table) {
            TableSessionService::rotateCode($table);
            $n++;
        }

        log_activity('table_verify.rotate_all', $store, ['tables' => $n], $store->id);
        app_alert_success(___('New table code generated.'));

        return back();
    }

    /** Printable A6 tent cards — QR + the table's current code, one per card. */
    public function tentCards(Request $request, $store)
    {
        $store = $this->resolveStore($request, $store);
        [, $branchId] = $this->branchContext($request, $store);

        $tables = $this->tablesFor($store, $branchId);
        foreach ($tables as $table) {
            TableSessionService::codeFor($table);
        }

        return view('owner.tables.tent-cards', [
            'store' => $store,
            'tables' => $tables,
            'mode' => TableSessionService::rawMode($store->id),
        ]);
    }
}
