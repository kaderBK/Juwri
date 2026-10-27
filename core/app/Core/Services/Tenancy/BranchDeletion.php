<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Branch;

/**
 * P6-325: deleting a branch must not strand rows on a dead branch id (branch_id has no foreign key anywhere).
 * Every tenant table (TenantTables registry, Community + editions) that has a `branch_id` column falls in one class:
 *
 * - HISTORY: money, fiscal, stock and stay records. They keep their branch for ever (never rewrite fiscal or
 *   ledger history), so a branch that has any of them cannot be deleted — the owner switches it off instead.
 * - PER_BRANCH: rows that only mean something for that branch (overrides, counters, caches) and are unique per
 *   branch; they are deleted with it (moving them would collide with the target branch's own rows).
 * - KEEP: archival records (backups, transfers, compliance logs) that stay as they were.
 * - MOVE: staff pins, coupons, tables, devices, reservations, ... are RE-HOMED to the store's default
 *   (lowest-id remaining) branch, so it stays visible and usable. Rows with a NULL branch_id already mean "default
 *   branch" (ScopesToBranch) and follow the new default when the old default is deleted.
 *
 * A table with branch_id in none of the lists blocks the delete ('unclassified') until a developer classifies it
 * (BranchDeletionTest names it). The delete is also refused while the branch has an open table session.
 */
final class BranchDeletion
{
    /** Records that must keep their branch: any row blocks the delete. */
    public const HISTORY = [
        'orders', 'fiscal_documents', 'fiscal_devices', 'accounting_journals', 'expenses', 'stock_movements',
        'pos_shifts', 'pos_offline_records', 'pos_offline_card_payments', 'hotel_stays', 'hotel_bookings',
        'purchase_orders', 'stock_counts', 'waste_logs',
    ];

    /** Branch-only rows deleted with the branch. */
    public const PER_BRANCH = [
        'branch_menu', 'item_kitchen_stations', 'daily_tokens', 'pos_offline_snapshots', 'branch_stock_levels',
        'branch_hour_exceptions', // P6-288: the branch's holidays / special hours (FK cascades too)
    ];

    /** Archival rows left untouched. */
    public const KEEP = [
        'store_backups', 'store_transfers', 'checklist_runs', 'food_safety_logs', 'food_safety_checks',
    ];

    /** Rows re-homed to the store's default branch. */
    public const MOVE = [
        'aggregator_connections', 'bio_blocks', 'catering_requests', 'checklist_templates', 'combos', 'coupons',
        'delivery_slot_rules', 'hungerstation_catalog_jobs', 'hungerstation_event_logs', 'kitchen_stations',
        'phone_lines', 'pos_bridge_connections', 'pos_offline_devices', 'pos_terminals', 'print_jobs',
        'printer_profiles', 'reservations', 'service_requests', 'shift_notes', 'signage_screens', 'slot_holds',
        'staff_members', 'staff_shifts', 'store_backup_schedules', 'store_tables', 'table_merge_groups',
        'table_sessions', 'team_channels', 'waitlist_entries', 'whatsapp_conversations',
    ];

    /** @return list<string> branch_id tables in none of HISTORY / PER_BRANCH / KEEP / MOVE */
    public static function unclassified(): array
    {
        $known = [...self::HISTORY, ...self::PER_BRANCH, ...self::KEEP, ...self::MOVE];

        return array_values(array_diff(self::branchTables(), $known));
    }

    /**
     * Why the branch cannot be deleted, or null when it can.
     */
    public static function blocker(Branch $branch): ?string
    {
        if (self::unclassified() !== []) {
            return 'unclassified';
        }
        foreach (self::branchTables() as $table) {
            if (in_array($table, self::HISTORY, true) && DB::table($table)->where('branch_id', $branch->id)->exists()) {
                return 'history';
            }
        }
        if (Schema::hasTable('branch_stock_levels')
            && DB::table('branch_stock_levels')->where('branch_id', $branch->id)->where('current_stock', '!=', 0)->exists()) {
            return 'stock';
        }
        if (Schema::hasTable('table_sessions')
            && DB::table('table_sessions')->where('branch_id', $branch->id)->where('expires_at', '>', now())->exists()) {
            return 'open_session';
        }

        return null;
    }

    /**
     * Delete the branch and re-home / clear its rows in one transaction. Returns the blocker code when refused.
     */
    public static function delete(Branch $branch): ?string
    {
        return DB::transaction(function () use ($branch): ?string {
            $locked = Branch::whereKey($branch->id)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }
            if (($why = self::blocker($locked)) !== null) {
                return $why;
            }
            $target = Branch::where('store_id', $locked->store_id)->whereKeyNot($locked->id)->orderBy('id')->value('id');
            if ($target === null) {
                return 'last';
            }
            foreach (self::branchTables() as $table) {
                $rows = DB::table($table)->where('branch_id', $locked->id);
                if (in_array($table, self::PER_BRANCH, true)) {
                    $rows->delete();
                } elseif (in_array($table, self::MOVE, true)) {
                    // BL-036 round 4: a moved branch is a security change where one is tracked (staff_members).
                    $rows->update(['branch_id' => $target] + (Schema::hasColumn($table, 'security_changed_at') ? ['security_changed_at' => now()] : []));
                }
            }
            $locked->delete();

            return null;
        });
    }

    /** @return list<string> every registered tenant table that has a branch_id column (branches itself excluded) */
    public static function branchTables(): array
    {
        $out = [];
        foreach (array_keys(TenantTables::tables()) as $table) {
            if ($table !== 'branches' && Schema::hasTable($table) && Schema::hasColumn($table, 'branch_id')) {
                $out[] = $table;
            }
        }

        return $out;
    }
}
