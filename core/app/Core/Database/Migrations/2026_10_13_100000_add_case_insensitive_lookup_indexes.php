<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * P6-40b: P6-40 moved email / username / coupon-code lookups to `LOWER(col) = ?` so they match case-insensitively on
 * PostgreSQL. A plain btree on `col` cannot serve that predicate, so PostgreSQL and SQLite get expression indexes on
 * the same expression (store-scoped tables lead with store_id, as their lookups do). MySQL/MariaDB are skipped: their
 * default collation already compares case-insensitively and the existing indexes serve the lookups.
 *
 * Indexes are NOT unique: existing rows may differ only in case. Backfill: mixed-case values are lowercased only where
 * that cannot collide; colliding groups are left untouched and reported (log warning), never merged.
 */
return new class extends Migration
{
    /** index name => [table, column list SQL, value column, scope column|null] */
    private const INDEXES = [
        'users_lower_email_index' => ['users', 'lower(email)', 'email', null],
        'users_lower_username_index' => ['users', 'lower(username)', 'username', null],
        'customers_store_id_lower_email_index' => ['customers', 'store_id, lower(email)', 'email', 'store_id'],
        'staff_members_store_id_lower_email_index' => ['staff_members', 'store_id, lower(email)', 'email', 'store_id'],
        'coupons_lower_code_index' => ['coupons', 'lower(code)', 'code', null],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns, $column, $scope]) {
            if (! Schema::hasTable($table)) {
                continue; // module not installed
            }
            if (in_array($column, ['email', 'username'], true)) {
                $this->backfill($table, $column, $scope);
            }
            if ($this->supported()) {
                DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
            }
        }
    }

    public function down(): void
    {
        if (! $this->supported()) {
            return;
        }
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }

    private function supported(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }

    /** Lowercase mixed-case values whose lowercase form is unique (within the scope); report the rest. */
    private function backfill(string $table, string $column, ?string $scope): void
    {
        if (! $this->supported()) {
            return; // MySQL: case-insensitive collation, `col <> LOWER(col)` is never true there
        }

        $key = $scope ? "{$scope}, lower({$column})" : "lower({$column})";
        $collisions = DB::table($table)
            ->selectRaw(($scope ? "{$scope} as scope, " : '')."lower({$column}) as k, count(*) as n")
            ->whereNotNull($column)
            ->groupByRaw($key)
            ->havingRaw('count(*) > 1')
            ->get();

        $match = $scope ? "({$scope}, lower({$column}))" : "lower({$column})";
        $dupes = "select {$key} from {$table} where {$column} is not null group by {$key} having count(*) > 1";
        DB::update("update {$table} set {$column} = lower({$column}) where {$column} is not null and {$column} <> lower({$column}) and {$match} not in ({$dupes})");

        if ($collisions->isNotEmpty()) {
            Log::warning("P6-40b: {$table}.{$column} has values differing only in case; left as-is for manual review", [
                'groups' => $collisions->count(), // no values logged (PII); find them with GROUP BY lower(col) HAVING count(*) > 1
                'rows' => (int) $collisions->sum('n'),
            ]);
        }
    }
};
