<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5-26: a SoftPOS charge can pay a POS order. order_id (same store), the tip it carries, and settled_at — the
 * one-time claim that marks the order paid (set once, under the order lock, by the POS settlement listener).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminal_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->after('parent_id');
            $table->bigInteger('tip_minor')->nullable()->after('amount_minor');
            $table->timestamp('settled_at')->nullable();
            $table->index(['store_id', 'order_id'], 'terminal_payments_store_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('terminal_payments', function (Blueprint $table) {
            $table->dropIndex('terminal_payments_store_order_idx');
            $table->dropColumn(['order_id', 'tip_minor', 'settled_at']);
        });
    }
};
