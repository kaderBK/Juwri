<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Backup;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\LegacyMoneyColumns;
use Juwri\Core\Services\Tenancy\AccountProvisioner;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Core\Services\Tenancy\TenantTables;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\Sql;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Payments\Services\StorePaymentGateways;
use RuntimeException;

/**
 * C3: registry-complete export and id-preserving restore of one store for the
 * superadmin full backup/restore engine (dark, behind admin_backup_registry_engine).
 *
 * The one design decision that keeps this correct and small: the restore
 * PRESERVES posts.id. Because the id is kept, every child row's store_id /
 * store_id and every cross-table FK (menu.category_id, order_items.item_id,
 * gl_journal_lines.journal_id, …) is still valid exactly as exported — no id map,
 * no remapping pass, no topological sort. That removes the entire class of bug
 * this WI exists to fix (the legacy engine minted a new id and orphaned 62 tables)
 * instead of re-implementing 68 hand-written FK remappers.
 */
class AdminStoreArchive
{
    /**
     * Registry-complete export of one restaurant, keyed by registry file name
     * plus post / store_options / theme_settings / orders / order_items /
     * order_item_extras. Raw DB rows (NOT scrubbed): this archive is the restore
     * source and only leaves the box through the ownership-checked
     * admin.users.fullExport download.
     *
     * @return array<string, mixed>
     */
    public function export(Store $store): array
    {
        $out = ['store' => $store->getAttributes()];
        $out['store_options'] = StoreSettings::legacyRows((int) $store->id); // P1-07: stores.settings as legacy rows
        $out['theme_settings'] = $this->rows(DB::table('theme_settings')->where('store_id', $store->id));
        // P1-07: gateway settings travel WITHOUT credentials (they never left the box before either).
        $out['store_payment_gateways'] = array_map(
            fn (array $row) => ['credentials' => null] + $row,
            $this->rows(DB::table('store_payment_gateways')->where('store_id', $store->id))
        );

        foreach (StoreBackupService::tableRegistry() as $key => $spec) {
            $out[$key] = StoreBackupService::registryScopedQuery($spec, $store)
                ->get()->map(fn ($m) => $m->getAttributes())->all();
        }

        $orderIds = DB::table($this->table(Order::class))->where('store_id', $store->id)->select('id');
        $itemIds = DB::table($this->table(OrderItem::class))->whereIn('order_id', clone $orderIds)->select('id');
        $out['orders'] = $this->rows(DB::table($this->table(Order::class))->where('store_id', $store->id));
        $out['order_items'] = $this->rows(DB::table($this->table(OrderItem::class))->whereIn('order_id', clone $orderIds));
        $out['order_item_extras'] = $this->rows(DB::table($this->table(OrderItemExtra::class))->whereIn('order_item_id', clone $itemIds));

        return $out;
    }

    /**
     * Restore one restaurant from a schema-2 section map, preserving posts.id and
     * every child key. Returns table => rows written. Throws (inside the caller's
     * DB::transaction, so nothing is half-written) when the id is occupied.
     *
     * @param  array<string, mixed>  $restaurantBackup
     * @return array<string, int>
     */
    public function restore(User $user, array $restaurantBackup): array
    {
        $postRow = (array) ($restaurantBackup['store'] ?? []);
        $storeId = $postRow['id'] ?? null;
        if (! $storeId) {
            throw new RuntimeException('registry restore: the archived store has no id to preserve.');
        }

        // Occupied-id fallback. clearExistingUserData() has just deleted THIS
        // user's posts, so this id is free unless it belongs to a different
        // account (a cross-platform archive, or a reused id). Refuse rather than
        // silently re-key a full store archive — silent re-keying is the bug.
        if (DB::table('stores')->where('id', $storeId)->exists()) {
            throw new RuntimeException(
                'Store id '.$storeId.' is occupied by another account; the registry restore engine '
                .'cannot re-key a full store archive. Restore into the original platform, or '
                .'turn admin_backup_registry_engine off to use the legacy partial restore.'
            );
        }

        return Sql::withoutForeignKeyChecks(function () use ($postRow, $user, $restaurantBackup, $storeId): array {
            $written = [];

            $postRow['user_id'] = $user->id;
            // P1-04 slice 5: the restoring owner's account, never the archived one; child rows follow below.
            $postRow['account_id'] = AccountProvisioner::forUser($user)->id;
            // P1-05: an older archive carries the decimal `mk_min_order` in the store's (archived) currency.
            $postRow = LegacyMoneyColumns::upgradeRow('stores', $postRow, self::archivedCurrency($restaurantBackup));
            DB::table('stores')->insert($this->clean($postRow));
            Sql::syncIdSequence('stores');
            TenantFill::forgetStore((int) $storeId);
            $written['stores'] = 1;

            $written['store_options'] = $this->restoreSettings((int) $storeId, (array) ($restaurantBackup['store_options'] ?? []));
            $written['theme_settings'] = $this->insertRows('theme_settings', $restaurantBackup['theme_settings'] ?? []);
            $written['store_payment_gateways'] = $this->insertRows('store_payment_gateways', array_map(
                fn ($row) => ['credentials' => null, 'store_id' => $storeId] + (array) $row,
                (array) ($restaurantBackup['store_payment_gateways'] ?? [])
            ));

            foreach (StoreBackupService::tableRegistry() as $key => $spec) {
                $rows = (array) ($restaurantBackup[$key] ?? []);
                $table = $this->table($spec['model']);
                if ($table === 'store_tables') {
                    $rows = $this->freeTableKeys($rows);
                }
                $written[$key] = $this->insertRows($table, $rows);
            }

            $written['orders'] = $this->insertRows($this->table(Order::class), $restaurantBackup['orders'] ?? []);
            $written['order_items'] = $this->insertRows($this->table(OrderItem::class), $restaurantBackup['order_items'] ?? []);
            $written['order_item_extras'] = $this->insertRows($this->table(OrderItemExtra::class), $restaurantBackup['order_item_extras'] ?? []);

            return $written;
        });
    }

    /**
     * store_tables.key is globally unique. With id preservation the exported
     * key is normally free after the purge, so it is KEPT (printed QR codes keep
     * working) and only regenerated when a real collision exists.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function freeTableKeys(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $key = $row['key'] ?? null;
            if ($key !== null && DB::table('store_tables')->where('key', $key)->exists()) {
                do {
                    $new = Str::random(13);
                } while (DB::table('store_tables')->where('key', $new)->exists());
                $rows[$i]['key'] = $new;
            }
        }

        return $rows;
    }

    /**
     * P1-07: legacy `store_options` rows (name/value) go back into stores.settings (which the archive's
     * store row may already carry; setting a value again is idempotent).
     *
     * @param  array<int, mixed>  $rows
     */
    private function restoreSettings(int $storeId, array $rows): int
    {
        $values = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            if (isset($row['option_name']) && ! StorePaymentGateways::handles((string) $row['option_name'])) {
                $values[(string) $row['option_name']] = $row['option_value'] ?? null;
            }
        }
        if ($values !== []) {
            StoreSettings::setMany($storeId, $values);
        }

        return count($values);
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function insertRows(string $table, array $rows): int
    {
        $rows = array_values(array_filter(array_map(fn ($r) => $this->tenantKeys($table, $this->legacyMoney($table, $this->renameLegacyColumns($table, $this->clean((array) $r)))), $rows)));
        if ($rows === []) {
            return 0;
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
        Sql::syncIdSequence($table);

        return count($rows);
    }

    /**
     * Columns renamed since an archive may have been written (P1-04: non-tenant `account_id`
     * columns). An archive row keeps its old key only when the new key is absent, so a later
     * tenant `account_id` on the same table is never mistaken for the old column.
     *
     * @var array<string, array<string, string>>
     */
    private const LEGACY_COLUMNS = [
        'accounting_journal_lines' => ['account_id' => 'gl_account_id'],
    ];

    /** P1-04 slice 3: the store column's names before the rename (any tenant table). */
    private const LEGACY_STORE_COLUMNS = ['restaurant_id', 'post_id'];

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function renameLegacyColumns(string $table, array $row): array
    {
        // P1-04 slice 3: every tenant table's store column was renamed; an archive written
        // before that carries the old name (only mapped when `store_id` is absent).
        foreach (self::LEGACY_STORE_COLUMNS as $old) {
            if (array_key_exists($old, $row)) {
                if (! array_key_exists('store_id', $row)) {
                    $row['store_id'] = $row[$old];
                }
                unset($row[$old]);
            }
        }
        foreach (self::LEGACY_COLUMNS[$table] ?? [] as $old => $new) {
            if (array_key_exists($old, $row) && ! array_key_exists($new, $row)) {
                $row[$new] = $row[$old];
                unset($row[$old]);
            }
        }

        return $row;
    }

    /**
     * P1-05: the store currency recorded in an archive's settings rows, else the platform's.
     *
     * @param  array<string, mixed>  $restaurantBackup
     */
    public static function archivedCurrency(array $restaurantBackup): string
    {
        foreach ((array) ($restaurantBackup['store_options'] ?? []) as $row) {
            $row = (array) $row;
            if (($row['option_name'] ?? null) === 'currency_code' && ! empty($row['option_value'])) {
                return (string) $row['option_value'];
            }
        }

        return (string) (config('settings.currency_code') ?: 'USD');
    }

    /**
     * P1-05: an archive written before the money columns became `*_minor` carries the decimal (major) keys —
     * convert them in the row's currency (its own currency_code, else its order's, else its store's).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function legacyMoney(string $table, array $row): array
    {
        $map = LegacyMoneyColumns::TABLES[$table] ?? [];
        if ($map === [] || array_intersect_key($row, $map) === []) {
            return $row;
        }
        $currency = null;
        if (in_array($table, ['order_items', 'order_adjustments', 'order_refund_items', 'table_checks'], true) && ! empty($row['order_id'])) {
            $currency = DB::table('orders')->where('id', $row['order_id'])->value('currency_code');
        } elseif ($table === 'check_payments' && ! empty($row['table_check_id'])) {
            $currency = DB::table('table_checks')->where('table_checks.id', $row['table_check_id'])
                ->join('orders', 'orders.id', '=', 'table_checks.order_id')->value('orders.currency_code');
        } elseif ($table === 'order_item_extras' && ! empty($row['order_item_id'])) {
            $currency = DB::table('order_items')->where('order_items.id', $row['order_item_id'])
                ->join('orders', 'orders.id', '=', 'order_items.order_id')->value('orders.currency_code');
        }
        if (! $currency && ! empty($row['store_id'])) {
            $currency = store_currency((int) $row['store_id']);
        }

        return LegacyMoneyColumns::upgradeRow($table, $row, $currency ? (string) $currency : null);
    }

    /**
     * P1-04 slice 5: an archived account_id is never trusted — recomputed from the (id-preserved) store row.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function tenantKeys(string $table, array $row): array
    {
        if (TenantTables::get($table) === null) {
            return $row;
        }
        unset($row['account_id']);

        return TenantFill::row(DB::connection(), $table, $row);
    }

    /**
     * Drop keys that must never be written verbatim. Nothing today, but the seam exists.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function clean(array $row): array
    {
        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(Builder $query): array
    {
        return $query->get()->map(fn ($r) => (array) $r)->all();
    }

    private function table(string $model): string
    {
        return (new $model)->getTable();
    }
}
