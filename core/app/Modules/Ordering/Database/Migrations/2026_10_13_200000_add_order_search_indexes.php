<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-101b: indexes for the SearchTerm order searches (owner list/export, dashboard palette, reseller cross-store).
 * Evidence: the P6-101b benchmark run (1M orders / 200 stores, EXPLAIN before/after on MySQL 8.4 and PG 18).
 *
 * - MySQL/MariaDB: (store_id, created_at, status, customer_name, phone_number). The owner list filters one store,
 *   skips `unpaid` and sorts by created_at: this index walks the store newest-first and, through index condition
 *   pushdown, evaluates `status <> ..`, the name LIKE and the phone prefix on the index entry, so only matching rows
 *   are fetched. Before, every order of the store was read from the table (random page reads, seconds when the
 *   store's rows are not cached). The reseller IN (...) search uses the same index.
 * - PostgreSQL: (store_id, phone_number text_pattern_ops). SearchTerm sends `phone_number::text ILIKE '9665..%'`;
 *   a digit-only ILIKE prefix is case-invariant, so a pattern-ops btree serves it as a range. Measured and rejected:
 *   pg_trgm GIN on customer_name / phone_number (slower — phone numbers share their country-code trigrams and
 *   common names match most of a store; the store_id bitmap is already cheap), and (store_id, created_at) (unused).
 * - MySQL FULLTEXT: not added — the search is LIKE '%x%', which cannot use it. sqlite: nothing (dev only).
 *
 * Online-safe: PostgreSQL uses CREATE INDEX CONCURRENTLY (hence no transaction); InnoDB adds secondary indexes in place.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const MYSQL = 'orders_store_search_idx';

    private const PGSQL = 'orders_store_phone_pattern_idx';

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $this->pgIndex(self::PGSQL, '("store_id", "phone_number" text_pattern_ops)');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true) && ! Schema::hasIndex('orders', self::MYSQL)) {
            Schema::table('orders', fn ($t) => $t->index(['store_id', 'created_at', 'status', 'customer_name', 'phone_number'], self::MYSQL));
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('drop index concurrently if exists "'.self::PGSQL.'"');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasIndex('orders', self::MYSQL)) {
            Schema::table('orders', fn ($t) => $t->dropIndex(self::MYSQL));
        }
    }

    /** CREATE INDEX CONCURRENTLY, replacing an INVALID leftover of an interrupted earlier build. */
    private function pgIndex(string $name, string $definition): void
    {
        $index = DB::selectOne(
            'select i.indisvalid as valid from pg_index i join pg_class c on c.oid = i.indexrelid
             where c.relname = ? and c.relnamespace = current_schema()::regnamespace',
            [$name]
        );
        if ($index !== null && $index->valid) {
            return;
        }
        if ($index !== null) {
            DB::statement('drop index concurrently if exists "'.$name.'"');
        }
        DB::statement('create index concurrently "'.$name.'" on "'.DB::getTablePrefix().'orders" '.$definition);
    }
};
