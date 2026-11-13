<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-036 A1b: what a performed undo switched off (the webhook endpoints created after the
 * email change), kept on the undo row so the done page and support can point the owner at
 * them. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_change_undos') || Schema::hasColumn('email_change_undos', 'meta')) {
            return;
        }
        Schema::table('email_change_undos', function (Blueprint $table) {
            $table->jsonb('meta')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('email_change_undos') && Schema::hasColumn('email_change_undos', 'meta')) {
            Schema::table('email_change_undos', function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }
    }
};
