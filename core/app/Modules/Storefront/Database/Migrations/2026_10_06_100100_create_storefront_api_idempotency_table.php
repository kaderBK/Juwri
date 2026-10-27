<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P3-06b (kit/api README §2.7, API-24c): the answers of the headless storefront API's non-order writes (customer OTP
 * request / verify) by `Idempotency-Key`, so a retry with the same key and body gets the first answer again
 * (`Idempotent-Replayed: true`) — no second text message, the same customer token — and the same key with another
 * body is refused (409 `idempotency_conflict`). Orders keep their key in `orders.client_uuid`.
 *
 * One row per store + operation + key (UNIQUE: two concurrent first calls cannot both run). `response_body` is
 * encrypted (it can hold a customer token). Rows expire (`expires_at`) and are pruned daily. Store tenant table:
 * account_id + store_id, store-leading keys, PostgreSQL RLS switched on here (the RLS migration ran earlier).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('storefront_api_idempotency')) {
            Schema::create('storefront_api_idempotency', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->string('operation', 40);
                $table->string('idempotency_key', 64);
                $table->char('request_hash', 64);
                $table->smallInteger('response_status')->unsigned()->nullable();
                $table->text('response_body')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('expires_at');
                $table->unique(['store_id', 'operation', 'idempotency_key'], 'storefront_api_idem_store_op_key_unique');
                $table->index(['store_id', 'expires_at'], 'storefront_api_idem_store_expires_index');
                $table->index(['account_id', 'store_id'], 'storefront_api_idem_account_store_index');
                $table->foreign(['store_id'], 'storefront_api_idem_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            });
        }

        $db = DB::connection();
        if (RowLevelSecurity::supported($db) && RowLevelSecurity::enabled()) {
            foreach (array_merge(RowLevelSecurity::functionStatements(), RowLevelSecurity::tableStatements('storefront_api_idempotency', false)) as $sql) {
                $db->statement($sql);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_api_idempotency');
    }
};
