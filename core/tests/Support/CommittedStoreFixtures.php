<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Tenancy\TenantTables;

/**
 * Clean-up for tests whose fixture rows are COMMITTED (cross-connection race tests: no per-test transaction). Deleting
 * only the tables a test wrote itself is not enough: creating a store also writes rows elsewhere (its default branch,
 * settings, ...), and a row left behind breaks later tests in the same process that need an empty table — e.g. the
 * legacy importer's guard refuses while `branches` holds rows it did not write.
 *
 * purge() deletes every row of the given stores from every store-scoped tenant table (several passes, so foreign keys
 * between those tables never decide the order), then the stores, their owners' accounts and the owners.
 */
final class CommittedStoreFixtures
{
    /** @param  list<int|string>  $storeIds */
    public static function purge(array $storeIds): void
    {
        $storeIds = array_values(array_unique(array_map('intval', $storeIds)));
        if ($storeIds === []) {
            return;
        }
        $schema = DB::getSchemaBuilder();
        $tables = [];
        foreach (TenantTables::tables() as $table => $meta) {
            if (($meta['store'] ?? false) && $table !== 'stores' && $schema->hasTable($table) && $schema->hasColumn($table, 'store_id')) {
                $tables[] = $table;
            }
        }
        $owners = DB::table('stores')->whereIn('id', $storeIds)->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->all();

        for ($pass = 0; $pass < 5 && $tables !== []; $pass++) {
            $left = [];
            foreach ($tables as $table) {
                try {
                    DB::table($table)->whereIn('store_id', $storeIds)->delete();
                } catch (\Throwable) {
                    $left[] = $table; // a row of another table still points at it: next pass
                }
            }
            $tables = $left;
        }

        DB::table('stores')->whereIn('id', $storeIds)->delete();
        if ($owners !== []) {
            DB::table('users')->whereIn('id', $owners)->update(['account_id' => null]);
            DB::table('accounts')->whereIn('owner_user_id', $owners)->delete();
            DB::table('users')->whereIn('id', $owners)->delete();
        }
    }
}
