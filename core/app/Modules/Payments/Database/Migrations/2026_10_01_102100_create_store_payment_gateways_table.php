<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-07: one row per store + payment gateway (was ~115 `restaurant_{gateway}_*` post_options keys over
 * 28 gateways plus the gateway half of the `gateway_secrets` credential vault row).
 *
 * - `config` (jsonb): the non-secret fields (install flag, title, sandbox mode, public keys,
 *   merchant / store ids …) keyed by field name.
 * - `credentials` (text): the secret fields, Laravel-encrypted JSON (model cast `encrypted:array`);
 *   never rendered back in full — settings screens show SecretMask values.
 * - `enabled` mirrors the install flag so "which gateways does this store offer" is one indexed query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->string('gateway', 40);
            $table->boolean('enabled')->default(false);
            $table->jsonb('config')->nullable();
            $table->text('credentials')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['store_id', 'gateway'], 'store_payment_gateways_store_id_gateway_unique');
            $table->index(['account_id', 'store_id'], 'store_payment_gateways_account_id_store_id_index');
            $table->foreign(['store_id'], 'store_payment_gateways_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_gateways');
    }
};
