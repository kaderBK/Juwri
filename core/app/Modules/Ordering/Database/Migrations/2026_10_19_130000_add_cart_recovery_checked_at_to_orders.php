<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-327: cart recovery batching marker. An unpaid order that was examined but had
 * nothing sent (missing customer data, no consent, no phone) was re-selected every
 * hour forever. cart_recovery_checked_at records that examination so it drops out of
 * the hourly scan; wa_cart_recovery_at keeps its meaning (stamped only when a
 * channel actually fired). NULL on every existing order.
 */
return new class extends Migration
{
    private const INDEX = 'orders_cart_recovery_checked_idx';

    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'cart_recovery_checked_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dateTimeTz('cart_recovery_checked_at')->nullable();
            });
        }

        if (! Schema::hasIndex('orders', self::INDEX)) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index('cart_recovery_checked_at', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('orders', self::INDEX)) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('orders', 'cart_recovery_checked_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('cart_recovery_checked_at');
            });
        }
    }
};
