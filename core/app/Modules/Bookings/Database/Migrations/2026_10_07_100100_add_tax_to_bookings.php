<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3-11: a booking carries the tax the store adds (an order's rule: a percentage of the subtotal or a fixed amount, on
 * top). `total_minor` is what the customer pays in all (services + tax); `tax_minor` is the part of it that is tax.
 * Bookings made before this column existed had no tax: 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->bigInteger('tax_minor')->default(0)->after('deposit_minor');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('tax_minor');
        });
    }
};
