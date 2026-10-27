<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-17 (J27-B): full-text index on customers.name for the Scout database search (customer names).
 *
 * MySQL/MariaDB: FULLTEXT (customers_name_fulltext). PostgreSQL: GIN on to_tsvector('simple', name) — the `simple` config
 * (no stemming, no stopwords) because names are Arabic and English alike; the search uses the same expression
 * (Juwri\Core\Search\DatabaseSearchEngine). sqlite has no full-text index: the search is LIKE only there.
 * Index only, no new table (nothing for the store-data registry).
 */
return new class extends Migration
{
    private const INDEX = 'customers_name_fulltext';

    public function up(): void
    {
        if (! $this->supported() || Schema::hasIndex('customers', self::INDEX)) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->fullText('name', self::INDEX)->language('simple');
        });
    }

    public function down(): void
    {
        if (! $this->supported() || ! Schema::hasIndex('customers', self::INDEX)) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropFullText(self::INDEX);
        });
    }

    private function supported(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true);
    }
};
