<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-246: a paid / refunded payment is never hard-deleted by an admin. "Delete" on such a row voids it instead: the row
 * stays (receipts, tax and dispute matching read it), stamped with who voided it, when and why (reason required).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transaction', 'voided_at')) {
            return;
        }

        Schema::table('transaction', function (Blueprint $table) {
            $table->dateTimeTz('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('transaction', 'voided_at')) {
            Schema::table('transaction', function (Blueprint $table) {
                $table->dropColumn(['voided_at', 'voided_by', 'void_reason']);
            });
        }
    }
};
