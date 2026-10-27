<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-272: how many times an order's customer receipt has been printed. Every print after the first
 * carries a COPY mark (tax rules), so the count lives in the database, never in a cache. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('receipt_printed_at')->nullable();
            $table->unsignedInteger('receipt_print_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['receipt_printed_at', 'receipt_print_count']);
        });
    }
};
