<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * P3-02: the storefront view model (and the headless API after it) exposes only public ids. A priced variant
 * (`menu_variants`, the size x colour matrix and the legacy priced combos) is user-facing — a cart line names the
 * variant it bought — so it gets the UUIDv7 `public_id` every other user-facing catalogue row has (J25-1,
 * Blueprint::publicId(): nullable + unique). Existing rows are backfilled here; new rows get one from
 * MenuVariant (HasUuids), a store duplicate gives fresh ones (MenuStoreData) and an archive restore mints one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('menu_variants', 'public_id')) {
            Schema::table('menu_variants', function (Blueprint $table) {
                $table->publicId();
            });
        }

        DB::table('menu_variants')->whereNull('public_id')->orderBy('id')->select('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('menu_variants')->where('id', $row->id)->update(['public_id' => (string) Str::uuid7()]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('menu_variants', 'public_id')) {
            Schema::table('menu_variants', function (Blueprint $table) {
                $table->dropUnique('menu_variants_public_id_unique');
                $table->dropColumn('public_id');
            });
        }
    }
};
