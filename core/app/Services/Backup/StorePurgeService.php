<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Backup;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\Sql;
use Juwri\Modules\Ordering\Models\OrderItemExtra;

/**
 * C3: registry-driven teardown of one store's rows, driven by
 * StoreBackupService::tableRegistry() plus the two-hop order tables and the
 * store_options/theme_settings specials. Used by the admin full-restore before a
 * post is deleted (so nothing is ever left pointing at a dead posts.id) and by
 * the dry-run preview (count-only, writes nothing).
 *
 * Deletes go through the raw query builder — NOT Eloquent — on purpose:
 *  - some store models (e.g. Menu) use SoftDeletes; a raw DELETE hard-removes the
 *    row so a re-import with the SAME preserved id cannot collide with a trashed
 *    tombstone, and no store-scoped row survives with deleted_at set;
 *  - it never hydrates 400k loyalty rows.
 *
 * On MySQL the deletes run with FOREIGN_KEY_CHECKS off: a full store teardown
 * removes every table that references the store at once, so only the END state
 * (all gone) needs to be FK-consistent. That frees us from hand-ordering 68
 * tables whose inter-FK direction is inconsistent. sqlite has FK checks off by
 * default, so nothing changes there.
 */
class StorePurgeService
{
    /**
     * Delete (or, in dry-run, count) every store-scoped row for one restaurant.
     * Returns file-key => row count.
     *
     * `$keep` lists file-keys to leave untouched (owner account deletion keeps the
     * financial/tax tables — orders, fiscal documents, GL — while wiping the rest).
     *
     * @param  list<string>  $keep
     * @return array<string, int>
     */
    public function purge(Store $store, bool $dryRun = false, array $keep = []): array
    {
        if ($dryRun) {
            $out = [];
            foreach ($this->queries($store) as $key => $q) {
                if (in_array($key, $keep, true)) {
                    continue;
                }
                $out[$key] = $q->count();
            }
            if (! in_array('store_options', $keep, true)) {
                $out['store_options'] = count(StoreSettings::legacyRows((int) $store->id)); // P1-07: settings tree, not rows
            }

            return $out;
        }

        return Sql::withoutForeignKeyChecks(function () use ($store, $keep): array {
            $out = [];
            foreach ($this->queries($store) as $key => $q) {
                if (in_array($key, $keep, true)) {
                    continue;
                }
                $out[$key] = $q->delete();
            }
            if (! in_array('store_options', $keep, true)) {
                // P1-07: the settings live in stores.settings (no store_options rows any more); the key stays in the report.
                $out['store_options'] = count(StoreSettings::legacyRows((int) $store->id));
                StoreSettings::forgetAll((int) $store->id);
            }

            return $out;
        });
    }

    /**
     * Fresh query per key (a builder is single-use once ->delete()/->count() runs).
     * Order: order children, then the registry, then the specials. With FK checks
     * off the order is for readability only.
     *
     * @return array<string, Builder>
     */
    private function queries(Store $store): array
    {
        $q = [];

        // Two-hop order children first (extra -> item -> order).
        $q['order_item_extras'] = DB::table($this->table(OrderItemExtra::class))
            ->whereIn('order_item_id', DB::table($this->table(OrderItem::class))
                ->whereIn('order_id', DB::table($this->table(Order::class))->where('store_id', $store->id)->select('id'))
                ->select('id'));
        $q['order_items'] = DB::table($this->table(OrderItem::class))
            ->whereIn('order_id', DB::table($this->table(Order::class))->where('store_id', $store->id)->select('id'));
        $q['orders'] = DB::table($this->table(Order::class))->where('store_id', $store->id);

        foreach (StoreBackupService::tableRegistry() as $key => $spec) {
            $q[$key] = $this->scopedTable($spec, $store);
        }

        // Non-registry specials the export also writes (store_options is handled in purge(): stores.settings).
        $q['theme_settings'] = DB::table('theme_settings')->where('store_id', $store->id);

        return $q;
    }

    /**
     * Raw-table query scoped to one store, resolving the three registry scope
     * kinds exactly as StoreBackupService::registryScopedQuery does — but on the
     * base builder so soft-deletes and model events are bypassed for the delete.
     *
     * @param  array<string, mixed>  $spec
     */
    private function scopedTable(array $spec, Store $store): Builder
    {
        $table = $this->table($spec['model']);

        if (! empty($spec['branch_only'])) {
            return DB::table($table)->whereIn('branch_id',
                DB::table($this->table(Branch::class))->where('store_id', $store->id)->select('id'));
        }
        if (! empty($spec['parent'])) {
            [$fk, $parentModel, $pscope] = $spec['parent'];

            return DB::table($table)->whereIn($fk,
                DB::table($this->table($parentModel))->where($pscope, $store->id)->select('id'));
        }

        return DB::table($table)->where($spec['scope'], $store->id);
    }

    /** Logical table name for a model class (respects $table overrides + the qrtest_ prefix via DB::table). */
    private function table(string $model): string
    {
        return (new $model)->getTable();
    }
}
