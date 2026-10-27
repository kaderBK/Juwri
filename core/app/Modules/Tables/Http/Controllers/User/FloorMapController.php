<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Http\Controllers\User;

use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreTable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Concerns\ScopesToBranch;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Tables\Services\TableMergeService;

class FloorMapController extends Controller
{
    use ScopesToBranch;

    private function ownerRestaurant(int $storeId): Store
    {
        return Store::where('id', $storeId)
            ->where('user_id', auth()->id())
            ->whereIn('id', dashboard_store_ids()) // BL-036: a staff session is bound to its own store
            ->firstOrFail();
    }

    /**
     * FP1: resolve the branch to scope the floor map to. Uses ?branch= when it belongs
     * to this restaurant; otherwise the restaurant's default (first) branch. A branch id
     * from another restaurant is rejected (404) — no cross-branch reach.
     */
    private function resolveBranchId(int $storeId, Request $request): ?int
    {
        $requested = $request->integer('branch');
        if ($requested) {
            $ok = Branch::where('id', $requested)->where('store_id', $storeId)->exists();
            abort_unless($ok, 404);

            return $requested;
        }

        return Branch::where('store_id', $storeId)->orderBy('id')->value('id');
    }

    /** Tables scoped to a branch via the shared ScopesToBranch rule (legacy NULL = default branch). */
    private function tableQuery(int $storeId, ?int $branchId)
    {
        return $this->scopeToBranch(StoreTable::where('store_id', $storeId), $storeId, $branchId);
    }

    public function index(int $storeId, Request $request)
    {
        $store = $this->ownerRestaurant($storeId);
        $branchId = $this->resolveBranchId($storeId, $request);
        $branches = Branch::where('store_id', $storeId)->orderBy('id')->get(['id', 'name']);
        $tables = $this->tableQuery($storeId, $branchId)->get();
        // All restaurants the owner has, so they can switch which floor plan to edit.
        $stores = Store::whereIn('id', dashboard_store_ids())->orderBy('id')->get(['id', 'title']);
        // FP7 #39: staff who can be assigned to sections/tables.
        $staffList = StaffMember::where('store_id', $storeId)
            ->where('active', 1)->orderBy('name')->get(['id', 'name']);
        // FP4 #22: the distinct rooms/zones (floor_labels) already defined in this branch.
        $rooms = $tables->pluck('floor_label')->filter()->unique()->sort()->values();

        /* PARITY-RUN T4: show the merge affordance only where it works. The
           endpoints carry `feature:table_merge` themselves — this just keeps the
           page from offering a button that would 403. */
        $canMerge = TableMergeService::enabled((int) $store->id);

        return view('owner.tables.floor-map', compact('store', 'stores', 'tables', 'branches', 'branchId', 'staffList', 'rooms', 'canMerge'));
    }

    // Save layout (x, y, shape, seats, floor_label) for all tables at once — scoped to the branch.
    public function saveLayout(Request $request, int $storeId)
    {
        $this->ownerRestaurant($storeId);
        $branchId = $this->resolveBranchId($storeId, $request);

        $validated = $request->validate([
            'tables' => 'required|array',
            'tables.*.id' => 'required|integer',
            'tables.*.map_x' => 'required|integer|min:0|max:4000',
            'tables.*.map_y' => 'required|integer|min:0|max:4000',
            'tables.*.shape' => 'required|in:square,circle,rectangle',
            'tables.*.seats' => 'required|integer|min:1|max:50',
            'tables.*.floor_label' => 'nullable|string|max:60',        // FP4 #22 room/zone
            'tables.*.section' => 'nullable|string|max:40',       // FP7 #38
            'tables.*.server_staff_id' => 'nullable|integer',            // FP7 #39
            'tables.*.note' => 'nullable|string|max:120',       // FP8 #46
            'tables.*.min_covers' => 'nullable|integer|min:1|max:50', // FP4 #26 capacity range
            'tables.*.max_covers' => 'nullable|integer|min:1|max:50',
        ]);

        // FP7 #39: only accept a server that actually belongs to this restaurant.
        $ownStaffIds = StaffMember::where('store_id', $storeId)->pluck('id')->flip();

        foreach ($validated['tables'] as $td) {
            $serverId = isset($td['server_staff_id']) && $ownStaffIds->has((int) $td['server_staff_id'])
                ? (int) $td['server_staff_id'] : null;

            // FP4 #26: normalise the capacity range — keep min ≤ max (swap if the host
            // entered them backwards), and never let it exceed the physical seat count.
            [$min, $max] = $this->normaliseCovers($td['min_covers'] ?? null, $td['max_covers'] ?? null, (int) $td['seats']);

            $this->tableQuery($storeId, $branchId) // branch-scoped (null→default), never cross-branch
                ->where('id', $td['id'])
                ->update([
                    'map_x' => $td['map_x'],
                    'map_y' => $td['map_y'],
                    'shape' => $td['shape'],
                    'seats' => $td['seats'],
                    'floor_label' => $td['floor_label'] ?? null, // FP4 #22 room/zone
                    'section' => $td['section'] ?? null,
                    'server_staff_id' => $serverId,
                    'note' => $td['note'] ?? null,
                    'min_covers' => $min,
                    'max_covers' => $max,
                ]);
        }

        log_activity('floor_map.save', null, ['table_count' => count($validated['tables']), 'branch_id' => $branchId], $storeId);

        return response()->json(['ok' => true]);
    }

    /**
     * FP4 #26: clamp a table's capacity range. Returns [min, max] where both are null
     * (fall back to 1..seats) or 1 ≤ min ≤ max ≤ seats. Swaps a backwards range.
     */
    private function normaliseCovers(?int $min, ?int $max, int $seats): array
    {
        if ($min === null && $max === null) {
            return [null, null];
        }
        $seats = max(1, $seats);
        $min = $min !== null ? max(1, min($min, $seats)) : 1;
        $max = $max !== null ? max(1, min($max, $seats)) : $seats;
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [$min, $max];
    }

    /** Add a new table to the current branch (auto-numbers). */
    public function addTable(Request $request, int $storeId)
    {
        $this->ownerRestaurant($storeId);
        $branchId = $this->resolveBranchId($storeId, $request);

        $nextNo = (int) (StoreTable::where('store_id', $storeId)->max('table_no')) + 1;
        $table = StoreTable::create([
            'store_id' => $storeId,
            'branch_id' => $branchId,
            'table_no' => $nextNo,
            'key' => Str::random(10),
            'seats' => 4,
            'shape' => 'square',
            'map_x' => 60,
            'map_y' => 60,
            'status' => 'free',
        ]);

        return response()->json([
            'id' => $table->id, 'table_no' => $table->table_no, 'key' => $table->key,
            'seats' => $table->seats, 'shape' => $table->shape,
            'map_x' => $table->map_x, 'map_y' => $table->map_y, 'status' => 'free',
        ], 201);
    }

    /** Delete a table (branch-scoped). */
    public function deleteTable(Request $request, int $storeId, int $tableId)
    {
        $this->ownerRestaurant($storeId);
        $branchId = $this->resolveBranchId($storeId, $request);
        $this->tableQuery($storeId, $branchId)->where('id', $tableId)->delete();

        return response()->json(['deleted' => true]);
    }

    // Live status data polled by the JS overlay — scoped to the branch.
    public function status(int $storeId, Request $request)
    {
        $this->ownerRestaurant($storeId);
        $branchId = $this->resolveBranchId($storeId, $request);

        $q = $this->tableQuery($storeId, $branchId);
        // FP4 #22: optionally scope the poll to a single room/zone.
        if ($request->filled('floor')) {
            $q->where('floor_label', $request->query('floor'));
        }
        $tables = $q->get();

        // Perf: one grouped query for every table's newest open order instead of a
        // per-table lookup. This endpoint is designed to be polled (FP4 #22), so an
        // N-table floor must not cost N queries. orderByDesc('id') keeps the first row
        // of each table_number group the newest — identical to the old per-table first().
        $openByTable = Order::where('store_id', $storeId)
            ->where('branch_id', $branchId)
            ->whereIn('table_number', $tables->pluck('table_no')->filter()->values()->all())
            ->whereIn('status', ['pending', 'preparing', 'ready', 'call_bill'])
            ->orderByDesc('id')
            ->get()
            ->groupBy('table_number');

        $statusMap = [];
        foreach ($tables as $t) {
            $order = ($openByTable[$t->table_no] ?? collect())->first();

            // FP2: the table's own status column is the source of truth. Only when it is
            // 'free' do we surface a live order's status (backward-compatible derivation).
            $tableStatus = $t->status ?: 'free';
            $status = $tableStatus !== 'free' ? $tableStatus : ($order ? $order->status : 'free');

            $statusMap[$t->id] = [
                'table_no' => $t->table_no,
                'map_x' => $t->map_x,
                'map_y' => $t->map_y,
                'shape' => $t->shape,
                'seats' => $t->seats,
                'floor_label' => $t->floor_label,
                'min_covers' => $t->min_covers,   // FP4 #26
                'max_covers' => $t->max_covers,
                'status' => $status,
                'party_size' => $t->party_size,
                'seconds_in_status' => $t->status_changed_at ? $t->status_changed_at->diffInSeconds(now()) : null,
                'order_id' => $order?->id,
            ];
        }

        return response()->json($statusMap);
    }

    /**
     * FP2: set a table's status (seat / clear / dirty→clean lifecycle). Branch-scoped;
     * a table from another restaurant/branch is rejected (404). Stamps status_changed_at.
     */
    public function setStatus(Request $request, int $storeId, int $tableId)
    {
        $this->ownerRestaurant($storeId);
        $branchId = $this->resolveBranchId($storeId, $request);

        $data = $request->validate([
            'status' => 'required|in:free,seated,ordering,food,bill,dirty,cleaning',
            'party_size' => 'nullable|integer|min:1|max:50',
        ]);

        $table = $this->tableQuery($storeId, $branchId)->where('id', $tableId)->firstOrFail();
        $table->status = $data['status'];
        $table->status_changed_at = now();
        $table->party_size = $data['status'] === 'free' ? null : ($data['party_size'] ?? $table->party_size);
        $table->save();

        return response()->json(['id' => $table->id, 'status' => $table->status]);
    }

    /**
     * FP9 #48/#49: turn-time + table-utilization analytics for a branch. Turn-time is
     * measured per completed on-table order as (completed_at − created_at); results are
     * rolled up per table and per section, plus a by-hour daypart histogram for the
     * heatmap. Branch-scoped; date window via ?days= (default 30, capped 365).
     */
    public function turnTimes(int $storeId, Request $request)
    {
        $this->ownerRestaurant($storeId);
        $branchId = $this->resolveBranchId($storeId, $request);
        $days = max(1, min(365, (int) ($request->integer('days') ?: 30)));
        $since = now()->subDays($days);

        // Section lookup: table_no → section (branch-scoped tables).
        $sections = $this->tableQuery($storeId, $branchId)->get(['table_no', 'section'])
            ->keyBy('table_no');

        $ordersQ = Order::where('store_id', $storeId)
            ->where('type', 'on-table')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereNotNull('table_number')
            ->where('created_at', '>=', $since);
        $this->scopeToBranch($ordersQ, $storeId, $branchId); // shared rule (Order has branch_id)
        $orders = $ordersQ->get(['table_number', 'created_at', 'completed_at']);

        $perTable = [];   // table_no => [count, total_minutes]
        $perSection = []; // section  => [count, total_minutes]
        $byHour = array_fill(0, 24, 0);

        foreach ($orders as $o) {
            $mins = $o->created_at && $o->completed_at
                ? max(0, $o->created_at->diffInMinutes($o->completed_at)) : 0;
            $tno = (string) $o->table_number;
            $perTable[$tno] ??= ['table_no' => $tno, 'orders' => 0, 'total' => 0];
            $perTable[$tno]['orders']++;
            $perTable[$tno]['total'] += $mins;

            $section = optional($sections->get($o->table_number))->section ?: '—';
            $perSection[$section] ??= ['section' => $section, 'orders' => 0, 'total' => 0];
            $perSection[$section]['orders']++;
            $perSection[$section]['total'] += $mins;

            $byHour[(int) $o->created_at->format('G')]++;
        }

        $avg = fn ($r) => ['orders' => $r['orders'], 'avg_minutes' => $r['orders'] ? round($r['total'] / $r['orders'], 1) : 0];

        $tables = collect($perTable)->map(fn ($r) => ['table_no' => $r['table_no']] + $avg($r))
            ->sortByDesc('orders')->values();
        $sectionsOut = collect($perSection)->map(fn ($r) => ['section' => $r['section']] + $avg($r))
            ->sortByDesc('orders')->values();

        return response()->json([
            'days' => $days,
            'branch_id' => $branchId,
            'total_orders' => $orders->count(),
            'overall_avg' => $orders->count()
                ? round($orders->sum(fn ($o) => $o->created_at && $o->completed_at ? max(0, $o->created_at->diffInMinutes($o->completed_at)) : 0) / $orders->count(), 1)
                : 0,
            'tables' => $tables,          // busiest-first (#48/#49)
            'sections' => $sectionsOut,
            'by_hour' => $byHour,          // daypart heatmap (#49)
        ]);
    }

    /** FP1: consolidated all-locations overview — table + seat counts per branch. */
    public function allLocations(int $storeId)
    {
        $this->ownerRestaurant($storeId);

        $rows = Branch::where('store_id', $storeId)->orderBy('id')->get(['id', 'name'])
            ->map(function ($b) use ($storeId) {
                $tables = StoreTable::where('store_id', $storeId)->where('branch_id', $b->id);

                return [
                    'branch_id' => $b->id,
                    'name' => $b->name,
                    'tables' => (clone $tables)->count(),
                    'seats' => (clone $tables)->sum('seats'),
                ];
            });

        return response()->json($rows);
    }
}
