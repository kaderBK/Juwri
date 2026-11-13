<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-271: the legacy (non VAT-engine) tax is charged on the subtotal AFTER the order discount. Every order placed
 * before this rule keeps the tax it was charged: it is marked `tax_pre_discount` = true (tax on the pre-discount
 * subtotal, the old rule) so its total never changes. NULL = the new rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('tax_pre_discount')->nullable();
        });
        DB::table('orders')->update(['tax_pre_discount' => true]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('tax_pre_discount');
        });
    }
};
