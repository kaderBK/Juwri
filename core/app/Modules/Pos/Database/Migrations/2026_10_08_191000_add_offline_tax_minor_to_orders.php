<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline POS (OFFLINE-POS-SPEC §4.3): an offline sale keeps the tax the till charged (minor units of the order's
 * currency). Order::computeMoney uses it instead of the store's live rate, so a tax-rate change made while the till was
 * offline never re-prices a sale the customer already paid. NULL on every other order (unchanged behaviour).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('offline_tax_minor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('offline_tax_minor');
        });
    }
};
