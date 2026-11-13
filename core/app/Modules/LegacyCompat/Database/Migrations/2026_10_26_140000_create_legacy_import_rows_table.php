<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * J52b importer side table (plan §1.1 rule 9): one row per source row the `legacy:import` command wrote, with a
 * checksum of the source row. Lives in the LegacyCompat module so core tables carry no import column (J52). Empty on
 * every install that never imports. Not a tenant table: it names source/target tables and ids only, no account/store.
 * It is also the resume marker: a store whose `stores` row is listed here was imported completely (one transaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('legacy_import_rows')) {
            return;
        }

        Schema::create('legacy_import_rows', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 64);
            $table->string('source_id', 64);
            $table->string('target_table', 64);
            $table->string('target_id', 64)->nullable();
            $table->char('source_checksum', 64);
            $table->string('run_id', 40);
            $table->dateTimeTz('created_at')->nullable();
            $table->unique(['source_table', 'source_id', 'target_table'], 'legacy_import_rows_source_target_unique');
            $table->index(['target_table', 'target_id'], 'legacy_import_rows_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_rows');
    }
};
