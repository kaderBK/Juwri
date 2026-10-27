<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Database\Connection;

/**
 * Read-only access to the source database (the old platform's schema). Only SELECTs are issued (plus, once, the
 * session time zone: {@see utcSession()}); table and column lists come from the live source schema (R1: production may
 * differ from any reference schema).
 *
 * Table prefix: the source may name every table `<prefix><name>` (connection `prefix`). Table names are listed and
 * compared WITHOUT the prefix; the query and schema builders add it back.
 *
 * Primary keys: most source tables use `id`, some do not (the platform options table, the plan-upgrades table); chunked
 * reads, id lists and single-row lookups use each table's own single-column primary key.
 */
final class SourceReader
{
    /** @var array<string, array<string, string>> table => column => lower-case type name */
    private array $columns = [];

    /** @var array<string, string|null> table => single-column primary key */
    private array $keys = [];

    /** @var list<string>|null */
    private ?array $tables = null;

    public function __construct(public readonly Connection $db, private readonly int $chunk = 500) {}

    /**
     * Pin the source session to UTC, so TIMESTAMP columns (stored as instants) read as UTC whatever the source server's
     * zone is; DATETIME columns are zone-less and unaffected (converted from the configured source zone). Returns the
     * session zone set, or null when the driver has none to set (sqlite).
     */
    public function utcSession(): ?string
    {
        return match ($this->db->getDriverName()) {
            'mysql', 'mariadb' => $this->db->statement("SET time_zone = '+00:00'") ? '+00:00' : null,
            'pgsql' => $this->db->statement("SET TIME ZONE 'UTC'") ? 'UTC' : null,
            default => null,
        };
    }

    /**
     * The source server's own zones, read BEFORE {@see utcSession()} (MySQL: `@@global.time_zone`,
     * `@@system_time_zone`, and this session's): the report shows what the old application's TIMESTAMPs were written
     * under. Empty for drivers without them.
     *
     * @return array<string, string|null>
     */
    public function serverZones(): array
    {
        if (! in_array($this->db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }
        $row = (array) $this->db->selectOne('select @@global.time_zone as global_zone, @@system_time_zone as system_zone, @@session.time_zone as session_zone');

        return array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $row);
    }

    /** @return list<string> source table names, without the connection's table prefix */
    public function tables(): array
    {
        if ($this->tables === null) {
            $prefix = $this->db->getTablePrefix();
            $names = [];
            foreach ($this->db->getSchemaBuilder()->getTables() as $t) {
                $name = (string) $t['name'];
                if ($prefix !== '') {
                    if (! str_starts_with($name, $prefix) || strlen($name) === strlen($prefix)) {
                        continue; // another application's table in the same schema
                    }
                    $name = substr($name, strlen($prefix));
                }
                $names[] = $name;
            }
            sort($names);
            $this->tables = array_values(array_unique($names));
        }

        return $this->tables;
    }

    public function has(string $table): bool
    {
        return in_array($table, $this->tables(), true);
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        return array_keys($this->types($table));
    }

    /** @return array<string, string> column => lower-case type name (the source's declared type) */
    public function types(string $table): array
    {
        if (! $this->has($table)) {
            return [];
        }
        if (! isset($this->columns[$table])) {
            $this->columns[$table] = [];
            foreach ($this->db->getSchemaBuilder()->getColumns($table) as $c) {
                $this->columns[$table][(string) $c['name']] = strtolower((string) $c['type_name']);
            }
        }

        return $this->columns[$table];
    }

    /**
     * Whether $column holds an INSTANT (MySQL TIMESTAMP, PostgreSQL timestamptz; a column declared `timestamp` in a
     * sqlite source): read in the UTC session, so it is already UTC. Every other date-time column is a zone-less wall
     * time of the source zone.
     */
    public function isInstant(string $table, string $column): bool
    {
        $type = $this->types($table)[$column] ?? '';

        return match ($this->db->getDriverName()) {
            'pgsql' => $type === 'timestamptz',
            default => $type === 'timestamp',
        };
    }

    /** The table's single-column primary key (`id` when the schema names none and the table has an `id`), or null. */
    public function key(string $table): ?string
    {
        if (! $this->has($table)) {
            return null;
        }
        if (! array_key_exists($table, $this->keys)) {
            $key = null;
            foreach ($this->db->getSchemaBuilder()->getIndexes($table) as $index) {
                if ($index['primary'] && count($index['columns']) === 1) {
                    $key = (string) $index['columns'][0];

                    break;
                }
            }
            if ($key === null && in_array('id', $this->columns($table), true)) {
                $key = 'id';
            }
            $this->keys[$table] = $key;
        }

        return $this->keys[$table];
    }

    /**
     * The first of $candidates the table has, or null.
     *
     * @param  string|list<string>  $candidates
     */
    public function pick(string $table, string|array $candidates): ?string
    {
        foreach ((array) $candidates as $name) {
            if (in_array($name, $this->columns($table), true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Rows of $table in primary-key order, $this->chunk at a time, optionally filtered by $column IN $values (values
     * chunked too). Keys above $afterId only. A table without a single-column primary key is not read (reported by
     * the caller as not imported).
     *
     * @param  list<int|string>|null  $values
     * @param  callable(list<array<string, mixed>>): void  $each
     */
    public function chunk(string $table, callable $each, ?string $column = null, ?array $values = null, int $afterId = 0): void
    {
        $key = $this->key($table);
        if ($key === null) {
            return;
        }
        if ($column !== null && $values !== null) {
            foreach (array_chunk(array_values(array_unique($values)), $this->chunk) as $part) {
                $rows = $this->db->table($table)->whereIn($column, $part)->orderBy($key)->get()->map(static fn ($r): array => (array) $r)->all();
                if ($rows !== []) {
                    $each(array_values($rows));
                }
            }

            return;
        }

        $last = $afterId;
        while (true) {
            $rows = $this->db->table($table)->where($key, '>', $last)->orderBy($key)->limit($this->chunk)->get()->map(static fn ($r): array => (array) $r)->all();
            if ($rows === []) {
                return;
            }
            $each(array_values($rows));
            $last = (int) end($rows)[$key];
        }
    }

    /**
     * @param  list<int>|null  $only
     * @return list<int> primary keys of $table (optionally restricted to $only), ascending
     */
    public function ids(string $table, ?array $only = null): array
    {
        $key = $this->key($table);
        if ($key === null) {
            return [];
        }
        $query = $this->db->table($table)->orderBy($key);
        if ($only !== null) {
            $query->whereIn($key, $only);
        }

        return array_map('intval', $query->pluck($key)->all());
    }

    /** @return array<string, mixed>|null */
    public function find(string $table, int $id): ?array
    {
        $key = $this->key($table);
        if ($key === null) {
            return null;
        }
        $row = $this->db->table($table)->where($key, $id)->first();

        return $row === null ? null : (array) $row;
    }

    /** @return list<array<string, mixed>> */
    public function where(string $table, string $column, mixed $value): array
    {
        if (! $this->has($table) || ! in_array($column, $this->columns($table), true)) {
            return [];
        }

        $query = $this->db->table($table)->where($column, $value);
        $key = $this->key($table);
        if ($key !== null) {
            $query->orderBy($key);
        }

        return array_values($query->get()->map(static fn ($r): array => (array) $r)->all());
    }
}
