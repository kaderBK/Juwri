<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Inserts the reference rows a module ships with (P1-03): one JSON file per table in the
 * module's Database/Migrations/data folder, written once by the schema generator.
 *
 * - '@now' values become the current UTC time.
 * - Rows keep their ids (other rows and code refer to them); on PostgreSQL the id sequence is
 *   moved past the highest id afterwards, so later inserts do not collide (the 23505
 *   "duplicate key" failures of the pre-P1-03 migrations on PostgreSQL).
 */
class ReferenceData
{
    /** @param  list<string>  $tables */
    public static function insert(string $dir, array $tables): void
    {
        $now = now('UTC')->format('Y-m-d H:i:s');
        foreach ($tables as $table) {
            $rows = self::rows($dir, $table);
            if ($rows === []) {
                continue;
            }
            $rows = array_map(fn (array $row) => array_map(fn ($v) => $v === '@now' ? $now : $v, $row), $rows);
            // SQLite allows 999 bound parameters per statement.
            $chunk = max(1, intdiv(900, max(1, count($rows[0]))));
            foreach (array_chunk($rows, $chunk) as $part) {
                DB::table($table)->insert($part);
            }
            self::resetSequence($table);
        }
    }

    /** @param  list<string>  $tables */
    public static function delete(string $dir, array $tables): void
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $key = self::keyColumn($table);
            $ids = array_column(self::rows($dir, $table), $key);
            foreach (array_chunk($ids, 500) as $part) {
                DB::table($table)->whereIn($key, $part)->delete();
            }
        }
    }

    public static function resetSequence(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $key = self::keyColumn($table);
        $grammar = DB::getQueryGrammar();
        DB::statement(sprintf(
            "select setval(pg_get_serial_sequence('%s', '%s'), coalesce((select max(%s) from %s), 0) + 1, false)",
            str_replace("'", "''", $table),
            str_replace("'", "''", $key),
            $grammar->wrap($key),
            $grammar->wrapTable($table)
        ));
    }

    private static function keyColumn(string $table): string
    {
        foreach (Schema::getColumns($table) as $column) {
            if ($column['auto_increment']) {
                return $column['name'];
            }
        }

        return 'id';
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $dir, string $table): array
    {
        $file = $dir.'/'.$table.'.json';
        if (! is_file($file)) {
            throw new RuntimeException("Reference data file missing: $file");
        }

        return json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }
}
