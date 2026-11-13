<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_mediamanager_files', function (Blueprint $table) {
            if (!Schema::hasColumn('plugin_mediamanager_files', 'is_trashed')) {
                $table->boolean('is_trashed')->default(false)->after('is_protected');
            }
            if (!Schema::hasColumn('plugin_mediamanager_files', 'trashed_at')) {
                $table->timestamp('trashed_at')->nullable()->after('is_trashed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plugin_mediamanager_files', function (Blueprint $table) {
            if (Schema::hasColumn('plugin_mediamanager_files', 'is_trashed')) {
                $table->dropColumn('is_trashed');
            }
            if (Schema::hasColumn('plugin_mediamanager_files', 'trashed_at')) {
                $table->dropColumn('trashed_at');
            }
        });
    }
};
