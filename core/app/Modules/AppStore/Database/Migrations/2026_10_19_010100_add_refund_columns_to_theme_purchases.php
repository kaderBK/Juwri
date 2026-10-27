<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-243: a refunded theme licence keeps its row (the sale and the refund are facts) and stops licensing the theme.
 * `refund_count` keys the wallet / payout refs of a later re-purchase so it is charged and credited again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('theme_purchases', 'refunded_at')) {
            return;
        }

        Schema::table('theme_purchases', function (Blueprint $table) {
            $table->dateTimeTz('refunded_at')->nullable();
            $table->unsignedBigInteger('refunded_by')->nullable();
            $table->string('refund_reason', 500)->nullable();
            $table->unsignedInteger('refund_count')->default(0);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('theme_purchases', 'refunded_at')) {
            Schema::table('theme_purchases', function (Blueprint $table) {
                $table->dropColumn(['refunded_at', 'refunded_by', 'refund_reason', 'refund_count']);
            });
        }
    }
};
