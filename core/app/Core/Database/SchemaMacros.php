<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Fluent;

/**
 * juwri schema conventions as Blueprint macros (P1-03, architecture page "Schema rules").
 *
 *  - $table->checkedString('status', ['a', 'b'], 32)
 *      Status columns are strings with a named CHECK constraint, never a native ENUM.
 *      MySQL / PostgreSQL: varchar + `alter table … add constraint <table>_<column>_check check (… in (…))`.
 *      SQLite (tests): Laravel's enum type, which SQLite compiles to `varchar check (… in (…))`
 *      (SQLite cannot add a constraint to an existing table).
 *
 *  - $table->publicId()
 *      UUIDv7 public id for user-facing entities (J25-1): native `uuid` on PostgreSQL,
 *      char(36) on MySQL (Laravel's uuid type; decision logged in progress/lane-b.md P1-03),
 *      nullable + unique until P1-04 backfills and makes it NOT NULL. Models fill it through
 *      HasUuids + uniqueIds() = ['public_id'] (Laravel 13 HasUuids generates UUIDv7).
 *
 *  - JuwriSchema::indexName($table, $suffix) keeps explicit index names ≤ 60 characters.
 */
class SchemaMacros
{
    public const MAX_NAME = 60;

    public static function register(): void
    {
        if (Blueprint::hasMacro('checkedString')) {
            return;
        }

        Blueprint::macro('checkedString', function (string $column, array $allowed, ?int $length = null): ColumnDefinition {
            /** @var Blueprint $this */
            $allowed = array_values(array_map('strval', $allowed));
            if ($this->connection->getSchemaGrammar() instanceof SQLiteGrammar) {
                return $this->enum($column, $allowed);
            }
            $length ??= max(32, ...array_map('strlen', $allowed));
            $definition = $this->string($column, $length);
            $this->addCommand('juwriCheck', [
                'column' => $column,
                'allowed' => $allowed,
                'index' => SchemaMacros::name($this->getTable(), $column.'_check'),
            ]);

            return $definition;
        });

        Blueprint::macro('publicId', function (string $column = 'public_id'): ColumnDefinition {
            /** @var Blueprint $this */
            $definition = $this->uuid($column)->nullable();
            $this->unique($column, SchemaMacros::name($this->getTable(), $column.'_unique'));

            return $definition;
        });

        Grammar::macro('compileJuwriCheck', function (Blueprint $blueprint, Fluent $command): ?string {
            /** @var Grammar $this */
            if ($this instanceof SQLiteGrammar) {
                return null;
            }
            $values = implode(', ', array_map(fn ($v) => "'".str_replace("'", "''", (string) $v)."'", (array) $command->get('allowed')));

            return sprintf(
                'alter table %s add constraint %s check (%s in (%s))',
                $this->wrapTable($blueprint),
                $this->wrap((string) $command->get('index')),
                $this->wrap((string) $command->get('column')),
                $values
            );
        });
    }

    /** Deterministic identifier (index/constraint) name of at most 60 characters. */
    public static function name(string $table, string $suffix): string
    {
        $name = strtolower($table.'_'.$suffix);
        if (strlen($name) <= self::MAX_NAME) {
            return $name;
        }

        return substr($name, 0, self::MAX_NAME - 9).'_'.substr(md5($name), 0, 8);
    }
}
