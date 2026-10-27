<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-23 part 7 (Terms 6.4): when the written notice before pausing an overdue paid app install was sent. Nullable: an
 * install that is past_due without it (every row before this) is never paused until a notice goes out + 7 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('app_installations', 'suspension_notice_at')) {
            return;
        }

        Schema::table('app_installations', function (Blueprint $table) {
            $table->dateTimeTz('suspension_notice_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('app_installations', 'suspension_notice_at')) {
            Schema::table('app_installations', function (Blueprint $table) {
                $table->dropColumn('suspension_notice_at');
            });
        }
    }
};
