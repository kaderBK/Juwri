<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Auth\CaseDuplicateFinder;

/**
 * P6-98: email/username must be unique case-insensitively, matching the P6-40 `LOWER(col) = ?` lookups.
 *
 * - PostgreSQL / SQLite: functional UNIQUE indexes on lower(col) (scoped like the existing unique constraints); they
 *   replace the identical non-unique P6-40b indexes.
 * - MySQL / MariaDB: a *_ci column collation (the default utf8mb4_unicode_ci) already makes the existing unique
 *   indexes case-insensitive, so nothing changes. Only a case-sensitive collation (_bin / _cs) gets a stored generated
 *   lowercase column with a unique index.
 *
 * Fails safely: if rows differ only in case, nothing is changed and the migration stops, naming the command that lists
 * them. Accounts are never merged automatically.
 */
return new class extends Migration
{
    /** unique index name => [table, column, scope|null, replaced P6-40b index|null] */
    private const UNIQUES = [
        'users_lower_email_unique' => ['users', 'email', null, 'users_lower_email_index'],
        'users_lower_username_unique' => ['users', 'username', null, 'users_lower_username_index'],
        'staff_members_lower_email_unique' => ['staff_members', 'email', null, null],
        'customers_store_id_lower_email_unique' => ['customers', 'email', 'store_id', 'customers_store_id_lower_email_index'],
    ];

    public function up(): void
    {
        $groups = (new CaseDuplicateFinder)->find();
        if ($groups !== []) {
            $tables = implode(', ', array_unique(array_map(fn ($g) => $g['table'].'.'.$g['column'], $groups)));
            throw new RuntimeException('P6-98: '.count($groups)." group(s) of accounts differ only in letter case ({$tables}). "
                .'Nothing was changed. Run `php artisan accounts:find-case-duplicates`, resolve each group by hand '
                .'(see docs/ops/case-duplicate-accounts.md), then run `php artisan migrate` again.');
        }

        $driver = Schema::getConnection()->getDriverName();
        foreach (self::UNIQUES as $name => [$table, $column, $scope, $replaced]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                $cols = ($scope ? "{$scope}, " : '')."lower({$column})";
                DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$name} ON {$table} ({$cols})");
                if ($replaced) {
                    DB::statement("DROP INDEX IF EXISTS {$replaced}");
                }
            } elseif (in_array($driver, ['mysql', 'mariadb'], true) && $this->caseSensitive($table, $column)) {
                $generated = "{$column}_lower";
                if (! Schema::hasColumn($table, $generated)) {
                    DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$generated}` VARCHAR(255) GENERATED ALWAYS AS (lower(`{$column}`)) STORED");
                }
                $cols = ($scope ? "`{$scope}`, " : '')."`{$generated}`";
                DB::statement("ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$name}` ({$cols})");
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        foreach (self::UNIQUES as $name => [$table, $column, $scope, $replaced]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                if ($replaced) {
                    $cols = ($scope ? "{$scope}, " : '')."lower({$column})";
                    DB::statement("CREATE INDEX IF NOT EXISTS {$replaced} ON {$table} ({$cols})");
                }
                DB::statement("DROP INDEX IF EXISTS {$name}");
            } elseif (Schema::hasColumn($table, "{$column}_lower")) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                DB::statement("ALTER TABLE `{$table}` DROP COLUMN `{$column}_lower`");
            }
        }
    }

    /** MySQL: true when the column's collation compares case-sensitively (_bin / _cs). */
    private function caseSensitive(string $table, string $column): bool
    {
        $collation = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->value('COLLATION_NAME');

        return is_string($collation) && ! str_ends_with(strtolower($collation), '_ci');
    }
};
