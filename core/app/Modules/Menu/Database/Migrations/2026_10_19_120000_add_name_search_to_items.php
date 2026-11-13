<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Support\SearchTerm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-295: a stored normalised search column on items.name so Arabic spelling variants (alef forms, ya/alef-maqsura,
 * ta-marbuta, tashkeel/tatweel, Arabic-Indic digits) all match one item. The Menu model keeps it on every save
 * (setNameAttribute); existing rows are backfilled here. The database search engine matches the normalised term
 * against this column, keeping the exact LIKE on name as a fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional: a run cut off mid-backfill (shared-hosting time limit) leaves the column in place,
        // so the re-run must skip the add and just finish the backfill.
        if (! Schema::hasColumn('items', 'name_search')) {
            Schema::table('items', function (Blueprint $table) {
                $table->string('name_search', 255)->nullable();
            });
        }

        $this->backfill();
    }

    /**
     * Fill the normalised name of rows that have none yet (idempotent: only whereNull rows; public for the regression test
     * and the `juwri:items:backfill-name-search` command). One UPDATE per distinct normalised value per chunk, not per row.
     */
    public function backfill(): void
    {
        DB::table('items')->whereNull('name_search')->whereNotNull('name')->orderBy('id')->select(['id', 'name'])
            ->chunkById(500, function ($rows): void {
                $byValue = [];
                foreach ($rows as $row) {
                    $byValue[SearchTerm::normalize((string) $row->name)][] = $row->id;
                }
                foreach ($byValue as $value => $ids) {
                    DB::table('items')->whereIn('id', $ids)->update(['name_search' => (string) $value]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('name_search');
        });
    }
};
