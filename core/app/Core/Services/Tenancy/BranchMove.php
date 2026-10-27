<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use App\Services\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;

/**
 * P6-341: moving a branch to another store of the same owner. Built on the P6-325 classification
 * ({@see BranchDeletion}) so there is ONE list of branch_id tables; every one of them gets a move rule here:
 *
 * - BLOCK_HISTORY: BranchDeletion::HISTORY plus the branch's compliance logs. Orders, fiscal documents/devices,
 *   journals, shifts, stock and stay records were issued by the source store and keep its store_id/account_id for
 *   ever (fiscal issuer data is never rewritten), so any such row refuses the move: the owner opens a new branch in
 *   the target store instead.
 * - BLOCK_LINKED: rows that point at the source store's own menu, customers or integration credentials (combos,
 *   coupons, aggregator/HungerStation links, WhatsApp chats, catering requests, bio blocks, phone lines, POS
 *   bridges). Re-keying them would leave cross-store references, so any such row refuses the move until removed.
 * - DROP: BranchDeletion::PER_BRANCH (menu overrides, item↔station links, token counters, offline snapshots — all
 *   keyed to the source store's items) plus the print queue; deleted. Stock left on hand blocks ('stock').
 * - STAY: archival rows (backups, transfer records) stay with the source store untouched.
 * - REKEY: everything else in BranchDeletion::MOVE follows the branch: store_id and account_id are set to the
 *   target store's, and so are their registered children (TenantTables::parents()).
 *
 * A branch_id table in none of the lists blocks the move ('unclassified'); BranchMoveTest names it.
 */
final class BranchMove
{
    /** Extra history kept with the source store (on top of BranchDeletion::HISTORY). */
    public const EXTRA_HISTORY = ['checklist_runs', 'food_safety_logs', 'food_safety_checks'];

    /** Rows referencing the source store's menu, customers or credentials: any row blocks. */
    public const BLOCK_LINKED = [
        'aggregator_connections', 'bio_blocks', 'catering_requests', 'combos', 'coupons',
        'hungerstation_catalog_jobs', 'hungerstation_event_logs', 'phone_lines', 'pos_bridge_connections',
        'whatsapp_conversations',
    ];

    /** Extra rows dropped on a move (on top of BranchDeletion::PER_BRANCH). */
    public const EXTRA_DROP = ['print_jobs'];

    /** Archival rows left with the source store. */
    public const STAY = ['store_backups', 'store_transfers'];

    /** @return list<string> */
    public static function blockHistory(): array
    {
        return [...BranchDeletion::HISTORY, ...self::EXTRA_HISTORY];
    }

    /** @return list<string> */
    public static function drop(): array
    {
        return [...BranchDeletion::PER_BRANCH, ...self::EXTRA_DROP];
    }

    /** @return list<string> MOVE tables that are re-keyed (not blocked/dropped/kept by a move rule) */
    public static function rekey(): array
    {
        return array_values(array_diff(
            BranchDeletion::MOVE,
            self::blockHistory(), self::BLOCK_LINKED, self::drop(), self::STAY,
        ));
    }

    /** @return list<string> branch_id tables with no move rule */
    public static function unclassified(): array
    {
        $known = [...self::blockHistory(), ...self::BLOCK_LINKED, ...self::drop(), ...self::STAY, ...self::rekey()];

        return array_values(array_diff(BranchDeletion::branchTables(), $known));
    }

    /** Why the branch cannot move to $target, or null when it can. */
    public static function blocker(Branch $branch, Store $target): ?string
    {
        if ((int) $target->id === (int) $branch->store_id) {
            return 'same_store';
        }
        if (self::unclassified() !== []) {
            return 'unclassified';
        }
        if (Branch::where('store_id', $branch->store_id)->count() <= 1) {
            return 'last';
        }
        $tables = BranchDeletion::branchTables();
        foreach (self::blockHistory() as $table) {
            if (in_array($table, $tables, true) && DB::table($table)->where('branch_id', $branch->id)->exists()) {
                return 'history';
            }
        }
        foreach (self::BLOCK_LINKED as $table) {
            if (in_array($table, $tables, true) && DB::table($table)->where('branch_id', $branch->id)->exists()) {
                return 'linked';
            }
        }
        if (in_array('branch_stock_levels', $tables, true)
            && DB::table('branch_stock_levels')->where('branch_id', $branch->id)->where('current_stock', '!=', 0)->exists()) {
            return 'stock';
        }
        if (in_array('table_sessions', $tables, true)
            && DB::table('table_sessions')->where('branch_id', $branch->id)->where('expires_at', '>', now())->exists()) {
            return 'open_session';
        }
        if (self::targetFull($target)) {
            return 'limit';
        }

        return null;
    }

    /**
     * Move the branch and re-key / drop its rows in one transaction. Returns the blocker code when refused.
     *
     * @return array{blocked: ?string, rekeyed: array<string, int>, dropped: array<string, int>}
     */
    public static function move(Branch $branch, Store $target): array
    {
        return DB::transaction(function () use ($branch, $target): array {
            $locked = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $target = Store::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if (($why = self::blocker($locked, $target)) !== null) {
                return ['blocked' => $why, 'rekeyed' => [], 'dropped' => []];
            }
            $set = ['store_id' => (int) $target->id, 'account_id' => $target->account_id];
            $tables = BranchDeletion::branchTables();
            $rekeyed = $dropped = [];

            foreach (self::drop() as $table) {
                if (in_array($table, $tables, true)) {
                    $dropped[$table] = DB::table($table)->where('branch_id', $locked->id)->delete();
                }
            }
            foreach (self::rekey() as $table) {
                if (! in_array($table, $tables, true)) {
                    continue;
                }
                $ids = DB::table($table)->where('branch_id', $locked->id)->pluck('id')->all();
                $rekeyed[$table] = self::rekeyRows($table, 'id', $ids, $set);
                self::rekeyChildren($table, $ids, $set, $rekeyed, 0);
            }
            $locked->update(['store_id' => (int) $target->id] + (Schema::hasColumn('branches', 'account_id') ? ['account_id' => $target->account_id] : []));

            return ['blocked' => null, 'rekeyed' => $rekeyed, 'dropped' => $dropped];
        });
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array<string, mixed>  $set
     */
    private static function rekeyRows(string $table, string $column, array $ids, array $set): int
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return 0;
        }
        $cols = array_filter($set, fn (string $col) => Schema::hasColumn($table, $col), ARRAY_FILTER_USE_KEY);
        if ($cols === []) {
            return 0;
        }
        $n = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $n += DB::table($table)->whereIn($column, $chunk)->update($cols);
        }

        return $n;
    }

    /**
     * Re-key the registered children of $parent rows (and their children), so e.g. staff tokens and team messages
     * follow their staff member / channel.
     *
     * @param  list<int|string>  $ids
     * @param  array<string, mixed>  $set
     * @param  array<string, int>  $rekeyed
     */
    private static function rekeyChildren(string $parent, array $ids, array $set, array &$rekeyed, int $depth): void
    {
        if ($ids === [] || $depth > 3) {
            return;
        }
        foreach (self::childrenOf($parent) as [$child, $fk]) {
            if (! Schema::hasTable($child) || ! Schema::hasColumn($child, $fk)) {
                continue;
            }
            $childIds = Schema::hasColumn($child, 'id')
                ? DB::table($child)->whereIn($fk, $ids)->pluck('id')->all()
                : [];
            $n = self::rekeyRows($child, $fk, $ids, $set);
            if ($n > 0) {
                $rekeyed[$child] = ($rekeyed[$child] ?? 0) + $n;
            }
            self::rekeyChildren($child, $childIds, $set, $rekeyed, $depth + 1);
        }
    }

    /** @return list<array{0: string, 1: string}> [child table, fk column] registered under $parent */
    private static function childrenOf(string $parent): array
    {
        $out = [];
        foreach (TenantTables::parents() as $child => $links) {
            foreach ($links as [$fk, $table]) {
                if ($table === $parent) {
                    $out[] = [$child, $fk];
                }
            }
        }

        return $out;
    }

    /** The target store's plan branch cap is already reached (the edition cap is enforced by the Branch model). */
    private static function targetFull(Store $target): bool
    {
        $owner = $target->user;
        if ($owner === null) {
            return false;
        }
        $limit = effective_limit('branch', $owner);

        return ! Entitlements::isUnlimitedSentinel($limit) && Branch::where('store_id', $target->id)->count() >= $limit;
    }
}
