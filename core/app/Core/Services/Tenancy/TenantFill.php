<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * P1-04 slice 5: fills the tenant keys of a new row (J29-1) — called from the BelongsToAccount `creating` hook.
 *
 *  1. store_id of a child row (P1-03 denormalised copy) ← its parent's store_id ({@see TenantTables::PARENTS}).
 *  2. account_id ← the store's account (rows with a store_id), or the owner's EXISTING account for the user-owned
 *     tables without one ({@see TenantTables::USER_OWNED}; transaction/billing_receipts carry a store only for order
 *     payments). Accounts are only created with a store
 *     ({@see AccountProvisioner}); a staff member, customer, driver or reseller team member never gets one.
 *
 * Values already set are never overwritten. Lookups are memoised per application instance (the test runner
 * reuses low ids across rolled-back databases) and forgotten when a store changes account.
 */
final class TenantFill
{
    /** @var array<int, int|null> store id => account id */
    private static array $storeAccounts = [];

    private static ?int $app = null;

    public static function fill(Model $model): void
    {
        $attributes = $model->getAttributes();
        $filled = self::row($model->getConnection(), $model->getTable(), $attributes);
        foreach (['store_id', 'account_id'] as $key) {
            if (($filled[$key] ?? null) !== ($attributes[$key] ?? null)) {
                $model->setAttribute($key, $filled[$key]);
            }
        }
    }

    /**
     * The same fill for a raw `DB::table($table)->insert($row)` (restores, bulk inserts): returns the row with
     * store_id / account_id completed. Values already set are kept.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function row(ConnectionInterface $db, string $table, array $row): array
    {
        $meta = TenantTables::get($table);
        if ($meta === null || $table === 'stores') {
            return $row; // the store sets its own account (Post saving hook / the restore sets it)
        }

        if ($meta['store'] && empty($row['store_id'])) {
            foreach (TenantTables::parents()[$table] ?? [] as [$fk, $parent]) {
                $parentId = $row[$fk] ?? null;
                if ($parentId && ($storeId = $db->table($parent)->where('id', $parentId)->value('store_id'))) {
                    $row['store_id'] = (int) $storeId;
                    break;
                }
            }
        }

        if (! $meta['account'] || ! empty($row['account_id'])) {
            return $row;
        }
        $accountId = null;
        if ($meta['store'] && ! empty($row['store_id'])) {
            $accountId = self::accountForStore($db, (int) $row['store_id']);
        } elseif (in_array($table, TenantTables::userOwned(), true) && ! empty($row['user_id'])) {
            // store-less owner rows, and optional-store ones without a store (transaction: plans/add-ons/top-ups)
            $accountId = $db->table('users')->where('id', $row['user_id'])->value('account_id');
        }
        if ($accountId) {
            $row['account_id'] = (int) $accountId;
        }

        return $row;
    }

    public static function accountForStore(ConnectionInterface $db, int $storeId): ?int
    {
        self::bindToApp();
        if (! array_key_exists($storeId, self::$storeAccounts)) {
            $id = $db->table('stores')->where('id', $storeId)->value('account_id');
            self::$storeAccounts[$storeId] = $id !== null ? (int) $id : null;
        }

        return self::$storeAccounts[$storeId];
    }

    public static function forgetStore(int $storeId): void
    {
        unset(self::$storeAccounts[$storeId]);
    }

    /**
     * Drop the whole store → account memo. Called at every queued-job boundary: a long-running worker keeps
     * one app across jobs, and an ownership transfer done by another process (rekeyStore) would otherwise
     * leave this worker filling new rows with the previous owner's account_id.
     */
    public static function flushStores(): void
    {
        self::$storeAccounts = [];
    }

    /**
     * A store moved to another account (ownership transfer): the store's rows follow it, per the P1-21 store-data
     * registry ({@see StoreDataEngine::rekeyTables()}: every Move/Reset table; StayWithOldAccount rows keep theirs).
     */
    public static function rekeyStore(ConnectionInterface $db, int $storeId, ?int $accountId): void
    {
        self::forgetStore($storeId);
        // Moves rows between accounts: platform work (the caller authorised the transfer) — explicit RLS bypass.
        TenantContext::bypass(function () use ($db, $storeId, $accountId): void {
            $schema = $db instanceof Connection ? $db->getSchemaBuilder() : Schema::getFacadeRoot();
            foreach (StoreDataEngine::rekeyTables() as $table) {
                // A table of an edition package's module (directory suggestions, …) is absent on a Community install.
                if (! $schema->hasTable($table)) {
                    continue;
                }
                $db->table($table)->where('store_id', $storeId)->update(['account_id' => $accountId]);
            }
        });
    }

    /** A new account was created for a user: their earlier store-less, user-owned rows join it. */
    public static function adoptUserRows(ConnectionInterface $db, int $userId, int $accountId): void
    {
        $schema = $db instanceof Connection ? $db->getSchemaBuilder() : Schema::getFacadeRoot();
        foreach (TenantTables::userOwned() as $table) {
            // P1-13 13f: an ee module's table (reseller_customer_subscriptions) is absent on a Community install.
            if (! $schema->hasTable($table)) {
                continue;
            }
            $query = $db->table($table)->where('user_id', $userId)->whereNull('account_id');
            if (TenantTables::get($table)['store'] ?? false) {
                $query->whereNull('store_id'); // a store row belongs to its store's account, never the payer's
            }
            $query->update(['account_id' => $accountId]);
        }
    }

    private static function bindToApp(): void
    {
        $id = \Juwri\Core\Support\RequestEpoch::id();
        if (self::$app !== $id) {
            self::$app = $id;
            self::$storeAccounts = [];
        }
    }
}
