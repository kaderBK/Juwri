<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Services;

use App\Models\Order;
use App\Models\StoreTable;
use App\Services\FeatureAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\RequestEpoch;
use Juwri\Modules\Tables\Models\TableCheck;
use Juwri\Modules\Tables\Models\TableMergeGroup;
use Juwri\Modules\Tables\Models\TableMergeMember;

/**
 * PARITY-RUN T4 — merge tables onto one bill, and move an open order to another
 * table.
 *
 * ## The one rule that shaped everything here
 *
 * Orders are created in exactly two places in this codebase (OrderApiService and
 * AggregatorIngestor) and a merge is not one of them. So a merge NEVER creates
 * an order, never copies a line item, and never re-prices anything: it re-points
 * the open orders of the secondary tables at the primary table's number and
 * remembers where each came from in `orders.merged_from_table_id`. Unmerge reads
 * that column back.
 *
 * ## Money
 *
 * The merged bill is the SUM of the participating orders' own
 * `Order::getTotalAttribute()` values — nothing is recomputed here. That is
 * deliberate: `getTotalAttribute()` is the price authority (tax on the bare item
 * subtotal, discount applied last, wallet/gift card as tenders rather than
 * discounts), and re-deriving a party total from line items would create a
 * second, drifting money surface. {@see groupBill()}.
 *
 * One consequence worth stating out loud: a store on a FIXED (not percentage)
 * tax charges that flat amount once per ORDER, so a merged party of three orders
 * is quoted three fixed taxes. That is exactly what the server charges for those
 * three orders when they are settled separately today — merging changes nothing
 * about it, and the alternative (one synthesised order) is forbidden above.
 *
 * ## Gating
 *
 * `table_merge` is kill-switchable. The owner's floor-map endpoints carry
 * `feature:table_merge` middleware (a dashboard route, never reached by a guest);
 * the register's endpoints ask {@see enabled()} in-controller because the staff
 * guard's user is not the buyer — the STORE OWNER is.
 */
class TableMergeService
{
    public const FEATURE = 'table_merge';

    /** Order statuses that still occupy a table (mirrors PosController::tables()). */
    public const OPEN_STATUSES = ['new', 'preparing', 'ready', 'served', 'pending', 'call_bill'];

    /** Per-request memo: store_id => "has any live merge". */
    private static array $anyGroupMemo = [];

    /** Per-request memo: "rid:branch:table_no" => party label (or null). */
    private static array $labelMemo = [];

    /** The application instance the memos above were filled for. */
    private static ?int $memoApp = null;

    /**
     * Bind the memos to the current application instance, as StoreOption does for
     * its option memo.
     *
     * Both memos are keyed by restaurant/table id alone, which says nothing about
     * WHICH database those ids came from. Under the test runner that is a
     * cross-class leak: RefreshDatabase rolls the rows back but hands the next
     * test the same low ids, so a party merged in one test would still label the
     * unrelated table that inherited its id in the next.
     */
    private static function scopeMemo(): void
    {
        $id = RequestEpoch::id();
        if (self::$memoApp !== $id) {
            self::$memoApp = $id;
            self::$anyGroupMemo = [];
            self::$labelMemo = [];
        }
    }

    /** Is merging available for this store right now? */
    public static function enabled($storeId): bool
    {
        $rid = (int) ($storeId instanceof Store ? $storeId->id : $storeId);

        return $rid > 0 && FeatureAccess::storeAllows($rid, self::FEATURE);
    }

    /* ===================================================================== */
    /* Branch identity */
    /* ===================================================================== */

    /**
     * The branch a table really belongs to. Legacy rows carry a NULL branch_id
     * and belong to the restaurant's DEFAULT branch (the canonical rule in
     * ScopesToBranch) — without this, a NULL table and a default-branch table
     * would look like different branches and merging them would be refused.
     */
    public static function effectiveBranchId(int $storeId, $branchId): ?int
    {
        if ($branchId !== null && $branchId !== '') {
            return (int) $branchId;
        }

        $default = Branch::where('store_id', $storeId)->orderBy('id')->value('id');

        return $default ? (int) $default : null;
    }

    /** The effective branch of a table row. */
    public static function tableBranchId(StoreTable $table): ?int
    {
        return self::effectiveBranchId((int) $table->store_id, $table->branch_id);
    }

    /**
     * The table a `table_number` refers to. Two branches may legitimately both
     * have a "Table 1", so the branch is part of the identity: when one is known
     * the match is made on the EFFECTIVE branch (legacy NULL = default), never
     * on a bare restaurant + number pair.
     */
    public static function tableByNumber(int $storeId, $tableNumber, $branchId = null): ?StoreTable
    {
        // table_no is an integer column: a non-numeric label matches nothing (PostgreSQL would raise 22P02).
        if ($tableNumber === null || $tableNumber === '' || ! is_numeric($tableNumber)) {
            return null;
        }

        $rows = StoreTable::where('store_id', $storeId)
            ->where('table_no', $tableNumber)
            ->orderBy('id')->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $wanted = self::effectiveBranchId($storeId, $branchId);
        if ($wanted === null) {
            return $rows->first();
        }

        return $rows->first(fn (StoreTable $t) => self::tableBranchId($t) === $wanted) ?: $rows->first();
    }

    /* ===================================================================== */
    /* Reads */
    /* ===================================================================== */

    /** The live group this table belongs to, or null. */
    public static function activeGroupFor(int $tableId): ?TableMergeGroup
    {
        $groupId = TableMergeMember::where('table_id', $tableId)
            ->whereIn('group_id', TableMergeGroup::active()->select('id'))
            ->value('group_id');

        return $groupId ? TableMergeGroup::with('members')->find($groupId) : null;
    }

    /**
     * Every live group in a store (optionally one branch), eager-loaded.
     *
     * @return Collection<int, TableMergeGroup>
     */
    public static function activeGroups(int $storeId, ?int $branchId = null): Collection
    {
        return TableMergeGroup::active()
            ->where('store_id', $storeId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->with('members')
            ->orderBy('id')
            ->get();
    }

    /**
     * table_id => group, for every table in a live group. The one query every
     * floor/POS screen needs to shade secondaries and badge primaries.
     *
     * @return array<int, TableMergeGroup>
     */
    public static function groupsByTableId(int $storeId, ?int $branchId = null): array
    {
        $out = [];
        foreach (self::activeGroups($storeId, $branchId) as $group) {
            foreach ($group->members as $member) {
                $out[(int) $member->table_id] = $group;
            }
        }

        return $out;
    }

    /**
     * Human label for a merged party, e.g. "T5 + T7". Built from the group's
     * table numbers with the primary first, so a KOT/KDS ticket says where the
     * food is actually going.
     */
    public static function label(TableMergeGroup $group, ?array $tableNumbers = null): string
    {
        if ($tableNumbers === null) {
            $rows = StoreTable::whereIn('id', $group->members->pluck('table_id'))->get(['id', 'table_no']);
            $tableNumbers = $rows->pluck('table_no', 'id')->all();
        }

        $primary = $tableNumbers[(int) $group->primary_table_id] ?? null;
        $others = [];
        foreach ($group->secondaryTableIds() as $id) {
            if (isset($tableNumbers[$id])) {
                $others[] = $tableNumbers[$id];
            }
        }
        sort($others, SORT_NATURAL);

        $parts = array_values(array_filter(array_merge([$primary], $others), fn ($v) => $v !== null && $v !== ''));

        return implode(' + ', array_map(fn ($n) => ___('table_no_prefix').$n, $parts));
    }

    /**
     * The still-open orders sitting on a merged party's primary table.
     *
     * @return Collection<int, Order>
     */
    public static function openOrdersForGroup(TableMergeGroup $group): Collection
    {
        $primary = StoreTable::find($group->primary_table_id);
        if (! $primary) {
            return new Collection;
        }

        return self::openOrdersAtTable((int) $group->store_id, $primary);
    }

    /**
     * Open (unpaid, un-held, live) orders sitting at one table. Branch-scoped
     * against the table's own effective branch so a same-numbered table in
     * another branch can never join the bill.
     *
     * @return Collection<int, Order>
     */
    public static function openOrdersAtTable(int $storeId, StoreTable $table): Collection
    {
        $branchId = self::tableBranchId($table);

        return Order::where('store_id', $storeId)
            ->whereNull('held_at')
            ->where('is_paid', 0)
            ->live()
            ->whereIn('status', self::OPEN_STATUSES)
            ->where('table_number', $table->table_no)
            ->when($branchId !== null, function ($q) use ($branchId, $storeId) {
                $default = Branch::where('store_id', $storeId)->orderBy('id')->value('id');
                if ((int) $branchId === (int) $default) {
                    return $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id'));
                }

                return $q->where('branch_id', $branchId);
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * The merged party's bill. `total` is the SUM of each order's own
     * `Order::getTotalAttribute()` — the server-side price authority — and
     * `amount_due` the sum of `Order::getAmountDueAttribute()`, which already
     * subtracts wallet / gift-card / platform-wallet TENDERS.
     *
     * @return array{orders: array<int, array<string, mixed>>, total: float, amount_due: float, order_count: int}
     */
    public static function groupBill(TableMergeGroup $group): array
    {
        $orders = self::openOrdersForGroup($group);

        $rows = $orders->map(fn (Order $o) => [
            'id' => (int) $o->id,
            'total' => (float) $o->total,
            'amount_due' => (float) $o->amount_due,
            'from_table_id' => $o->merged_from_table_id ? (int) $o->merged_from_table_id : null,
            'item_count' => (int) $o->items->sum('quantity'),
        ])->values()->all();

        return [
            'orders' => $rows,
            'order_count' => count($rows),
            'total' => (float) array_sum(array_column($rows, 'total')),
            'amount_due' => (float) array_sum(array_column($rows, 'amount_due')),
        ];
    }

    /**
     * "T5 + T7" for an order that sits on a merged party's primary table, or
     * null for an ordinary order. This is what a KOT / KDS ticket should print
     * instead of the bare table number: a runner sent to "T5" would otherwise
     * walk past half the party.
     *
     * Cheap and quiet by design — it is called from print and board paths, so a
     * store with no merges pays one indexed lookup and every failure mode
     * returns null rather than throwing into a ticket.
     */
    public static function partyLabelForOrder(?Order $order): ?string
    {
        if (! $order || $order->table_number === null || $order->table_number === '') {
            return null;
        }

        try {
            /* The KDS poll asks this for up to 100 tickets several times a
               minute, and virtually no store has a live merge at any given
               moment. One memoised existence check keeps that path at a single
               query per request instead of two per ticket. */
            if (! self::hasActiveGroups((int) $order->store_id)) {
                return null;
            }

            self::scopeMemo();
            $memoKey = $order->store_id.':'.($order->branch_id ?? '-').':'.$order->table_number;
            if (array_key_exists($memoKey, self::$labelMemo)) {
                return self::$labelMemo[$memoKey];
            }

            $table = self::tableByNumber((int) $order->store_id, $order->table_number, $order->branch_id);
            if (! $table) {
                return self::$labelMemo[$memoKey] = null;
            }
            $group = self::activeGroupFor((int) $table->id);
            if (! $group || (int) $group->primary_table_id !== (int) $table->id) {
                return self::$labelMemo[$memoKey] = null;
            }

            return self::$labelMemo[$memoKey] = self::label($group);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Does this store have ANY live merge? Memoised for the request — the hot
     * read paths (KDS poll, receipts) call it once per ticket.
     */
    public static function hasActiveGroups(int $storeId): bool
    {
        self::scopeMemo();

        if (array_key_exists($storeId, self::$anyGroupMemo)) {
            return self::$anyGroupMemo[$storeId];
        }

        return self::$anyGroupMemo[$storeId] = TableMergeGroup::active()
            ->where('store_id', $storeId)->exists();
    }

    /**
     * Drop the per-request memos. Merge/unmerge call this so a screen that
     * merges and then re-reads in the same request sees the new state; tests
     * use it too.
     */
    public static function flushMemo(): void
    {
        self::$anyGroupMemo = [];
        self::$labelMemo = [];
    }

    /**
     * QR/storefront resolution: a scan on ANY merged table lands on the party's
     * PRIMARY table, so the guest's order joins the one bill instead of opening
     * a second one on a table nobody is sitting at.
     */
    public static function resolvePrimaryTable(StoreTable $table): StoreTable
    {
        $group = self::activeGroupFor((int) $table->id);
        if (! $group || (int) $group->primary_table_id === (int) $table->id) {
            return $table;
        }

        return StoreTable::find($group->primary_table_id) ?: $table;
    }

    /**
     * Server-side twin of {@see resolvePrimaryTable()} for a POSTED table
     * number. A guest with a stale tab (or a hand-edited payload) naming a
     * merged secondary is routed to the primary, so the party cannot silently
     * end up with two bills. Returns the input unchanged for anything it cannot
     * resolve — this must never be able to reject an order.
     */
    public static function normalizeTableNumber(int $storeId, $tableNumber, $branchId = null)
    {
        if ($tableNumber === null || $tableNumber === '') {
            return $tableNumber;
        }

        $table = self::tableByNumber($storeId, $tableNumber, $branchId);
        if (! $table) {
            return $tableNumber;
        }

        return self::resolvePrimaryTable($table)->table_no;
    }

    /* ===================================================================== */
    /* Merge */
    /* ===================================================================== */

    /**
     * Merge $secondaryIds onto $primaryId.
     *
     * @param  array{staff_id?: int|null, user_id?: int|null}  $actor
     *
     * @throws \RuntimeException with a translated, user-facing message
     */
    public static function merge(int $storeId, int $primaryId, array $secondaryIds, array $actor = []): TableMergeGroup
    {
        if (! self::enabled($storeId)) {
            throw new \RuntimeException(___('Table merge is not available for this store.'));
        }

        $secondaryIds = array_values(array_unique(array_map('intval', array_filter($secondaryIds))));
        $secondaryIds = array_values(array_diff($secondaryIds, [$primaryId]));

        if (! $secondaryIds) {
            throw new \RuntimeException(___('Pick at least two tables to merge.'));
        }

        $tables = StoreTable::where('store_id', $storeId)
            ->whereIn('id', array_merge([$primaryId], $secondaryIds))
            ->get()->keyBy('id');

        $primary = $tables->get($primaryId);
        if (! $primary) {
            throw new \RuntimeException(___('That table does not belong to this restaurant.'));
        }

        foreach ($secondaryIds as $id) {
            if (! $tables->get($id)) {
                // Cross-store (or deleted) table — refuse, never reach across tenants.
                throw new \RuntimeException(___('That table does not belong to this restaurant.'));
            }
        }

        $branchId = self::tableBranchId($primary);
        foreach ($tables as $t) {
            if (self::tableBranchId($t) !== $branchId) {
                throw new \RuntimeException(___('Tables in different branches cannot be merged.'));
            }
            if ($t->isRoom()) {
                // Hotel rooms have their own folio flow (charge-to-room); merging
                // them here would silently bypass it.
                throw new \RuntimeException(___('Hotel rooms cannot be merged.'));
            }
        }

        // Already merged elsewhere? Extending the SAME group is fine; joining a
        // second one is not — a table can only sit on one bill.
        $existing = null;
        foreach ($tables as $t) {
            $group = self::activeGroupFor((int) $t->id);
            if (! $group) {
                continue;
            }
            if ((int) $group->primary_table_id === $primaryId) {
                $existing = $group;

                continue;
            }
            throw new \RuntimeException(___('One of these tables is already merged.'));
        }

        // A split bill in progress is a party mid-payment; folding another table
        // into it would change the amount under the payers' feet.
        foreach ($tables as $t) {
            foreach (self::openOrdersAtTable($storeId, $t) as $order) {
                if (self::hasSplitInProgress($order)) {
                    throw new \RuntimeException(___('Finish or cancel the split bill before merging this table.'));
                }
            }
        }

        return DB::transaction(function () use ($storeId, $primary, $secondaryIds, $tables, $branchId, $actor, $existing) {
            $group = $existing ?: TableMergeGroup::create([
                'store_id' => $storeId,
                'branch_id' => $branchId,
                'primary_table_id' => $primary->id,
                'created_by_staff_id' => $actor['staff_id'] ?? null,
                'created_by_user_id' => $actor['user_id'] ?? null,
            ]);

            if (! $existing) {
                TableMergeMember::create([
                    'group_id' => $group->id,
                    'table_id' => $primary->id,
                    'is_primary' => true,
                    'created_at' => now(),
                ]);
            }

            $moved = [];
            foreach ($secondaryIds as $id) {
                $secondary = $tables->get($id);

                TableMergeMember::firstOrCreate(
                    ['group_id' => $group->id, 'table_id' => $secondary->id],
                    ['is_primary' => false, 'created_at' => now()]
                );

                /* Re-point, never re-create: the order keeps its id, its items,
                   its discounts and its payments. `merged_from_table_id` is what
                   unmerge reads to put it back. */
                foreach (self::openOrdersAtTable($storeId, $secondary) as $order) {
                    $order->merged_from_table_id = $order->merged_from_table_id ?: $secondary->id;
                    $order->table_number = $primary->table_no;
                    $order->type = 'on-table';
                    $order->save();
                    $moved[] = (int) $order->id;
                }
            }

            $group->load('members');
            self::flushMemo();

            log_activity('table.merge', $group, [
                'group_id' => $group->id,
                'primary_table_id' => (int) $primary->id,
                'primary_table_no' => $primary->table_no,
                'secondary_table_ids' => $secondaryIds,
                'branch_id' => $branchId,
                'moved_order_ids' => $moved,
                'staff_id' => $actor['staff_id'] ?? null,
            ], $storeId);

            return $group;
        });
    }

    /**
     * Release a merge. Still-open orders go back to the table they came from;
     * anything already settled keeps its merged history.
     *
     * @param  array{staff_id?: int|null, user_id?: int|null}  $actor
     */
    public static function unmerge(TableMergeGroup $group, array $actor = [], string $reason = 'manual'): TableMergeGroup
    {
        if (! $group->isActive()) {
            return $group;
        }

        return DB::transaction(function () use ($group, $actor, $reason) {
            $group->loadMissing('members');
            $primary = StoreTable::find($group->primary_table_id);
            $restored = [];

            if ($primary) {
                $byId = StoreTable::whereIn('id', $group->secondaryTableIds())->get()->keyBy('id');

                foreach (self::openOrdersAtTable((int) $group->store_id, $primary) as $order) {
                    $from = $order->merged_from_table_id ? $byId->get((int) $order->merged_from_table_id) : null;
                    if (! $from) {
                        continue; // the primary's own orders stay put
                    }
                    $order->table_number = $from->table_no;
                    $order->merged_from_table_id = null;
                    $order->save();
                    $restored[] = (int) $order->id;
                }
            }

            $group->released_at = now();
            $group->release_reason = in_array($reason, ['manual', 'settled'], true) ? $reason : 'manual';
            $group->released_by_staff_id = $actor['staff_id'] ?? null;
            $group->released_by_user_id = $actor['user_id'] ?? null;
            $group->save();
            self::flushMemo();

            log_activity('table.unmerge', $group, [
                'group_id' => $group->id,
                'reason' => $group->release_reason,
                'restored_order_ids' => $restored,
                'staff_id' => $actor['staff_id'] ?? null,
            ], (int) $group->store_id);

            return $group;
        });
    }

    /**
     * Auto-release: once the merged party has settled everything on the primary
     * table, the tables go back to being tables. Called from the shared
     * `Order::booted()` `updated()` choke point, in its own try/catch, so it can
     * never break a payment.
     */
    public static function onOrderSettled(Order $order): void
    {
        if (! $order->is_paid || ! $order->store_id || $order->table_number === null) {
            return;
        }

        $table = self::tableByNumber((int) $order->store_id, $order->table_number, $order->branch_id);
        if (! $table) {
            return;
        }

        $group = self::activeGroupFor((int) $table->id);
        if (! $group || (int) $group->primary_table_id !== (int) $table->id) {
            return;
        }

        if (self::openOrdersForGroup($group)->isEmpty()) {
            self::unmerge($group, [], 'settled');
        }
    }

    /* ===================================================================== */
    /* Transfer */
    /* ===================================================================== */

    /**
     * Move ONE open order to another table. Payments, discounts, coupons, tips
     * and wallet tenders are untouched — only `table_number` (and `type`, so a
     * takeaway ticket moved onto a table becomes a dine-in one) changes, which
     * is why `Order::getTotalAttribute()` returns the same figure afterwards.
     *
     * @param  array{staff_id?: int|null, user_id?: int|null}  $actor
     *
     * @throws \RuntimeException with a translated, user-facing message
     */
    public static function transfer(Order $order, StoreTable $target, array $actor = []): Order
    {
        if ((int) $order->store_id !== (int) $target->store_id) {
            throw new \RuntimeException(___('That table does not belong to this restaurant.'));
        }

        if ((bool) $order->is_paid) {
            throw new \RuntimeException(___('A settled order cannot be moved.'));
        }

        // Cross-branch moves are refused for the same reason cross-branch merges
        // are: the kitchen, the floor and the shift that own the order all belong
        // to one branch.
        $orderBranch = self::effectiveBranchId((int) $order->store_id, $order->branch_id);
        if ($orderBranch !== null && self::tableBranchId($target) !== $orderBranch) {
            throw new \RuntimeException(___('Tables in different branches cannot be merged.'));
        }

        if (self::hasSplitInProgress($order)) {
            throw new \RuntimeException(___('Finish or cancel the split bill before moving this order.'));
        }

        // Moving onto a merged secondary means "join that party" — land on the
        // primary, exactly like a QR scan would.
        $resolved = self::resolvePrimaryTable($target);

        $fromTableNumber = $order->table_number;

        $order->table_number = $resolved->table_no;
        $order->type = 'on-table';
        // The order now belongs to wherever it landed, not to the merge it left.
        $order->merged_from_table_id = ((int) $resolved->id === (int) $target->id) ? null : (int) $target->id;
        $order->save();

        log_activity('order.transfer', $order, [
            'order_id' => (int) $order->id,
            'from_table_number' => $fromTableNumber,
            'to_table_id' => (int) $resolved->id,
            'to_table_number' => $resolved->table_no,
            'requested_table_id' => (int) $target->id,
            'staff_id' => $actor['staff_id'] ?? null,
        ], (int) $order->store_id);

        return $order;
    }

    /* ===================================================================== */
    /* Guards */
    /* ===================================================================== */

    /** Is this order in the middle of a split bill (created, not fully paid)? */
    public static function hasSplitInProgress(Order $order): bool
    {
        return TableCheck::where('order_id', $order->id)
            ->whereIn('status', ['open', 'partial'])
            ->exists();
    }
}
