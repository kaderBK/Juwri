<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers — the shopper ↔ store customer link.
 *
 * P1-13 13d follow-up: Community code reads the shopper ↔ store customer link (CustomerMergeService re-points it
 * when customers merge), which the ee Marketplace migration used to create — a Community install without the Enterprise
 * package had no such table. Same columns and indexes as before (P1-03 squashed schema). Idempotent: an install where the
 * Marketplace migration already created it skips the create. Timestamped before the tenant RLS migration
 * (2026_10_01_900000) so a fresh PostgreSQL install protects it too.
 *
 * Tables: shopper_customers
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shopper_customers')) {
            Schema::create('shopper_customers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('shopper_id')->unsigned();
                $table->bigInteger('store_id')->unsigned();
                $table->bigInteger('customer_id')->unsigned();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['customer_id'], 'shopper_customers_customer_id_index');
                $table->index(['store_id'], 'shopper_customers_store_id_index');
                $table->unique(['shopper_id', 'store_id'], 'shopper_customers_shopper_id_store_id_unique');
                $table->index(['account_id', 'store_id'], 'shopper_customers_account_id_store_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shopper_customers');
    }
};
