<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5-31: optional branch-level overrides for prep time, delivery fee and minimum order. Every column is nullable and
 * null means "use the store's value". Money is stored in minor units (J25-4) of the row's `currency_code` (the store's
 * currency when the amount was set). The branches table is already store-scoped (tenancy + RLS), so the columns need
 * nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->integer('prep_time_minutes')->nullable();
            $table->bigInteger('delivery_fee_minor')->nullable();
            $table->bigInteger('min_order_minor')->nullable();
            $table->char('currency_code', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['prep_time_minutes', 'delivery_fee_minor', 'min_order_minor', 'currency_code']);
        });
    }
};
