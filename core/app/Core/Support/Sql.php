<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Driver-neutral SQL fragments for the few spots where the query builder has no
 * equivalent (P1-10). MySQL 8.4, PostgreSQL 18 and SQLite (tests) are supported.
 * Column arguments are trusted SQL (a column name or expression built by the
 * caller), never user input.
 */
final class Sql
{
    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /** Text (string) cast of a column or expression; usable inside CASE branches next to string literals. */
    public static function castText(string $col): string
    {
        return self::driver() === 'mysql' || self::driver() === 'mariadb'
            ? "CAST({$col} AS CHAR)"
            : "CAST({$col} AS VARCHAR(64))";
    }

    /**
     * ORDER BY $col DESC with NULLs last on every driver (PostgreSQL sorts NULLs first on DESC; MySQL and
     * sqlite sort them last). Works on query builders and Eloquent builders.
     */
    public static function orderByDescNullsLast(mixed $query, string $col): mixed
    {
        return $query->orderByRaw(DB::connection()->getQueryGrammar()->wrap($col).' IS NULL')->orderByDesc($col);
    }

    /** Unix epoch seconds (integer) of a datetime column. */
    public static function epoch(string $col): string
    {
        return match (self::driver()) {
            'sqlite' => "CAST(strftime('%s', {$col}) AS INTEGER)",
            'pgsql' => "CAST(EXTRACT(EPOCH FROM {$col}) AS BIGINT)",
            default => "UNIX_TIMESTAMP({$col})",
        };
    }

    /** 'YYYY-MM-DD HH:MM:SS' text of a datetime column. */
    public static function dateTimeText(string $col): string
    {
        return match (self::driver()) {
            'sqlite' => "strftime('%Y-%m-%d %H:%M:%S', {$col})",
            'pgsql' => "to_char({$col}, 'YYYY-MM-DD HH24:MI:SS')",
            default => "DATE_FORMAT({$col}, '%Y-%m-%d %H:%i:%s')",
        };
    }

    /** Datetime expression shifted by an integer number of minutes. */
    public static function addMinutes(string $col, int $minutes): string
    {
        if ($minutes === 0) {
            return $col;
        }

        return match (self::driver()) {
            'sqlite' => "datetime({$col}, '".($minutes > 0 ? '+' : '-').abs($minutes)." minutes')",
            'pgsql' => "({$col} + INTERVAL '{$minutes} minutes')",
            default => "DATE_ADD({$col}, INTERVAL {$minutes} MINUTE)",
        };
    }

    /** Hour of day (0-23; sqlite yields a zero-padded string, cast at the caller). */
    public static function hour(string $col): string
    {
        return match (self::driver()) {
            'sqlite' => "strftime('%H', {$col})",
            'pgsql' => "CAST(EXTRACT(HOUR FROM {$col}) AS INTEGER)",
            default => "HOUR({$col})",
        };
    }

    /** Calendar date of a datetime expression. */
    public static function date(string $col): string
    {
        return match (self::driver()) {
            'sqlite' => "date({$col})",
            'pgsql' => "CAST({$col} AS DATE)",
            default => "DATE({$col})",
        };
    }

    /** Scalar text at a top-level key of a JSON document held in a text/json column. */
    public static function jsonKey(string $col, string $key): string
    {
        return match (self::driver()) {
            'sqlite' => "json_extract({$col}, '\$.{$key}')",
            'pgsql' => "(CASE WHEN left(ltrim(CAST({$col} AS TEXT)), 1) = '{' THEN CAST({$col} AS JSONB) ->> '{$key}' END)",
            default => "JSON_UNQUOTE(JSON_EXTRACT({$col}, '\$.{$key}'))",
        };
    }

    /**
     * Run a bulk delete/insert with FK enforcement off, restoring it afterwards.
     * MySQL: FOREIGN_KEY_CHECKS. PostgreSQL: session_replication_role when the role may set it
     * (superuser), otherwise the callback runs with FKs on and must be ordered children-first.
     * SQLite (tests) keeps enforcement on, so callers stay ordered.
     */
    public static function withoutForeignKeyChecks(Closure $callback): mixed
    {
        $driver = self::driver();
        $off = match (true) {
            $driver === 'mysql' || $driver === 'mariadb' => ['SET FOREIGN_KEY_CHECKS=0', 'SET FOREIGN_KEY_CHECKS=1'],
            $driver === 'pgsql' && (bool) (DB::selectOne("SELECT current_setting('is_superuser') = 'on' AS su")->su ?? false) => ['SET session_replication_role = replica', 'SET session_replication_role = DEFAULT'],
            default => null,
        };
        if ($off === null) {
            return $callback();
        }
        DB::statement($off[0]);
        try {
            return $callback();
        } finally {
            DB::statement($off[1]);
        }
    }

    /**
     * PostgreSQL only: after rows were inserted with explicit ids, move the table's id sequence
     * past the largest id so the next default insert does not collide (23505). No-op elsewhere.
     */
    public static function syncIdSequence(string $table, string $column = 'id'): void
    {
        if (self::driver() !== 'pgsql' || ! Schema::hasColumn($table, $column)) {
            return;
        }
        $wrapped = DB::connection()->getQueryGrammar()->wrapTable($table);
        $col = DB::connection()->getQueryGrammar()->wrap($column);
        DB::statement(
            'SELECT setval(pg_get_serial_sequence(?, ?), COALESCE(MAX('.$col.'), 1), MAX('.$col.') IS NOT NULL) FROM '.$wrapped,
            [DB::connection()->getTablePrefix().$table, $column]
        );
    }

    /**
     * SUM over rows locked FOR UPDATE. PostgreSQL rejects FOR UPDATE together with an aggregate, so
     * there the rows are locked with a plain select and summed here; MySQL keeps the aggregate.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder  $query
     */
    public static function lockedSum(mixed $query, string $column): float|int
    {
        if (self::driver() !== 'pgsql') {
            return $query->lockForUpdate()->sum($column);
        }
        $values = $query->lockForUpdate()->pluck($column);

        return $values->contains(fn ($v) => is_string($v) && str_contains($v, '.'))
            ? round((float) $values->sum(), 6)
            : (int) $values->sum();
    }

    /**
     * COUNT over rows locked FOR UPDATE (same PostgreSQL restriction as lockedSum()).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder  $query
     */
    public static function lockedCount(mixed $query): int
    {
        if (self::driver() !== 'pgsql') {
            return (int) $query->lockForUpdate()->count();
        }

        return $query->lockForUpdate()->pluck('id')->count();
    }
}
