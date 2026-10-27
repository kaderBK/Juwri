<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Http\Controllers\User;

use App\Models\StoreTable;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ScopesToBranch;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Tables\Models\TableMergeGroup;
use Juwri\Modules\Tables\Services\TableMergeService;

/**
 * PARITY-RUN T4 — the OWNER's half of table merge (dashboard floor map).
 *
 * The register's half lives in Staff\PosController; both are thin and delegate
 * to TableMergeService, so the two surfaces can never drift apart on what a
 * merge means.
 *
 * Why a separate controller rather than reusing the register's endpoints: the
 * POS routes require the owner's plan to include `pos_enabled` (see
 * PosController::context()), and merging tables on the floor map must not
 * demand a POS subscription. These routes carry `feature:table_merge`
 * middleware — safe here, because the dashboard is never reached by a guest.
 */
class TableMergeController extends Controller
{
    use ScopesToBranch;

    private function ownerRestaurant(int $storeId): Store
    {
        return Store::where('id', $storeId)
            ->where('user_id', auth()->id())
            ->whereIn('id', dashboard_store_ids()) // BL-036: a staff session is bound to its own store
            ->firstOrFail();
    }

    /** Same branch resolution as FloorMapController, so both read one floor. */
    private function resolveBranchId(int $storeId, Request $request): ?int
    {
        $requested = $request->integer('branch');
        if ($requested) {
            abort_unless(Branch::where('id', $requested)->where('store_id', $storeId)->exists(), 404);

            return $requested;
        }

        return Branch::where('store_id', $storeId)->orderBy('id')->value('id');
    }

    /**
     * Live merge groups for the floor map overlay: which tables are linked,
     * which one holds the bill, and what that bill currently comes to.
     */
    public function index(int $storeId, Request $request)
    {
        $this->ownerRestaurant($storeId);
        $branchId = $this->resolveBranchId($storeId, $request);

        return response()->json(['groups' => $this->payload($storeId, $branchId)]);
    }

    /** Merge two or more tables onto one bill. */
    public function store(Request $request, int $storeId)
    {
        $this->ownerRestaurant($storeId);
        $branchId = $this->resolveBranchId($storeId, $request);

        $data = $request->validate([
            'primary_table_id' => 'required|integer',
            'table_ids' => 'required|array|min:1',
            'table_ids.*' => 'integer',
        ]);

        try {
            $group = TableMergeService::merge(
                $storeId,
                (int) $data['primary_table_id'],
                $data['table_ids'],
                ['user_id' => auth()->id()]
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'group' => $this->groupPayload($group),
            'groups' => $this->payload($storeId, $branchId),
        ], 201);
    }

    /** Release a merge — still-open orders go back to their own tables. */
    public function destroy(Request $request, int $storeId, int $groupId)
    {
        $this->ownerRestaurant($storeId);
        $branchId = $this->resolveBranchId($storeId, $request);

        $group = TableMergeGroup::where('id', $groupId)
            ->where('store_id', $storeId)
            ->firstOrFail();

        TableMergeService::unmerge($group, ['user_id' => auth()->id()], 'manual');

        return response()->json(['released' => true, 'groups' => $this->payload($storeId, $branchId)]);
    }

    /** @return array<int, array<string, mixed>> */
    private function payload(int $storeId, ?int $branchId): array
    {
        return TableMergeService::activeGroups($storeId, $branchId)
            ->map(fn (TableMergeGroup $g) => $this->groupPayload($g))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function groupPayload(TableMergeGroup $group): array
    {
        $group->loadMissing('members');
        $tables = StoreTable::whereIn('id', $group->members->pluck('table_id'))
            ->get(['id', 'table_no', 'seats', 'label']);
        $numbers = $tables->pluck('table_no', 'id')->all();
        $bill = TableMergeService::groupBill($group);

        return [
            'id' => (int) $group->id,
            'primary_table_id' => (int) $group->primary_table_id,
            'table_ids' => $group->members->pluck('table_id')->map(fn ($id) => (int) $id)->values()->all(),
            'secondary_table_ids' => $group->secondaryTableIds(),
            'label' => TableMergeService::label($group, $numbers),
            // Capacity sums: a merged party's seats are the sum of its tables'.
            'seats' => (int) $tables->sum('seats'),
            'order_count' => $bill['order_count'],
            // Server-computed from Order::getTotalAttribute() — never a client sum.
            'total' => $bill['total'],
            'amount_due' => $bill['amount_due'],
        ];
    }
}
