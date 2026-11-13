<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery — fleet dispatch offers.
 *
 * P1-13 13d follow-up: Community code reads fleet dispatch offers (DispatchOrchestrator, the driver app:
 * MkDispatchOffer), which the ee Marketplace migration used to create — a Community install without the Enterprise
 * package had no such table. Same columns and indexes as before (P1-03 squashed schema). Idempotent: an install where the
 * Marketplace migration already created it skips the create. Timestamped before the tenant RLS migration
 * (2026_10_01_900000) so a fresh PostgreSQL install protects it too.
 *
 * Tables: marketplace_dispatch_offers
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_dispatch_offers')) {
            Schema::create('marketplace_dispatch_offers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->unsignedBigInteger('store_id')->nullable();
                $table->bigInteger('order_id')->unsigned();
                $table->bigInteger('driver_id')->unsigned();
                $table->string('status', 16)->default('offered');
                $table->dateTimeTz('expires_at')->nullable();
                $table->dateTimeTz('responded_at')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['driver_id'], 'marketplace_dispatch_offers_driver_id_index');
                $table->index(['order_id'], 'marketplace_dispatch_offers_order_id_index');
                $table->index(['status'], 'marketplace_dispatch_offers_status_index');
                $table->index(['account_id', 'store_id'], 'marketplace_dispatch_offers_account_id_store_id_index');
                $table->index(['store_id'], 'marketplace_dispatch_offers_store_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_dispatch_offers');
    }
};
