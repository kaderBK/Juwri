<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P6-350: track the last rejection and the number of rejections so a rejected review cannot be resubmitted without limit. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('marketplace_reviews', 'rejected_at')) {
            Schema::table('marketplace_reviews', function (Blueprint $table) {
                $table->timestamp('rejected_at')->nullable();
            });
        }
        if (! Schema::hasColumn('marketplace_reviews', 'rejection_count')) {
            Schema::table('marketplace_reviews', function (Blueprint $table) {
                $table->unsignedSmallInteger('rejection_count')->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['rejected_at', 'rejection_count'] as $col) {
            if (Schema::hasColumn('marketplace_reviews', $col)) {
                Schema::table('marketplace_reviews', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
