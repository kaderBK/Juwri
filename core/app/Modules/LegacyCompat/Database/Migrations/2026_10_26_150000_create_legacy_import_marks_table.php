<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * J52b importer money/history marks (plan §4, R2, Q3): what the `legacy:import` command could NOT take from the source
 * as recorded and had to approximate or leave open, per imported row — a line price snapshotted from the source menu
 * at migration time, a price that no longer exists, an order total derived from its lines, a tax the source never
 * stored, a source subtotal that disagrees with its lines. A side table inside the LegacyCompat module, so core tables
 * carry no import column (J52). Empty on every install that never imports. Not a tenant table: it names target
 * tables and ids only, no account/store.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('legacy_import_marks')) {
            return;
        }

        Schema::create('legacy_import_marks', function (Blueprint $table) {
            $table->id();
            $table->string('target_table', 64);
            $table->string('target_id', 64);
            $table->string('mark', 32);
            $table->text('detail')->nullable();
            $table->string('run_id', 40);
            $table->dateTimeTz('created_at')->nullable();
            $table->unique(['target_table', 'target_id', 'mark'], 'legacy_import_marks_target_mark_unique');
            $table->index(['mark'], 'legacy_import_marks_mark_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_marks');
    }
};
