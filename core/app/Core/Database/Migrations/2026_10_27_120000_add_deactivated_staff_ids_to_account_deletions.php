<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-036 round 4: the staff members an account closure switched off. A restore switches back on exactly these and no
 * others (never staff the owner had disabled, never staff added after the closure). Rows from before this column
 * (NULL) restore no staff; the owner switches them back on from the Staff page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('account_deletions') || Schema::hasColumn('account_deletions', 'deactivated_staff_ids')) {
            return;
        }
        Schema::table('account_deletions', function (Blueprint $table) {
            $table->jsonb('deactivated_staff_ids')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('account_deletions') || ! Schema::hasColumn('account_deletions', 'deactivated_staff_ids')) {
            return;
        }
        Schema::table('account_deletions', function (Blueprint $table) {
            $table->dropColumn('deactivated_staff_ids');
        });
    }
};
