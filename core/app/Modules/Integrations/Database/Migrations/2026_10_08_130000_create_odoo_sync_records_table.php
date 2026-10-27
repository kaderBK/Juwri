<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P4-21 Odoo connector (one-way Juwri → Odoo): the sync queue and its result, one row per store event —
 * `order` (a paid order: customer, invoice, payment, stock picking) or `refund` (a refund / void: credit note +
 * refund payment). UNIQUE(store_id, kind, source_id): an event is queued once and retried on the same row.
 * status: pending → synced | retrying (attempts, next_attempt_at with backoff) → failed (after the last attempt;
 * the owner can retry). `result` keeps the Odoo ids (partner, invoice, payment, picking) so a retry never creates a
 * second document; Odoo itself is also searched by the order's UUIDv7 reference first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_sync_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->checkedString('kind', ['order', 'refund'], 16);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('order_id');
            $table->string('reference', 120);
            $table->checkedString('status', ['pending', 'retrying', 'synced', 'failed', 'skipped'], 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTimeTz('next_attempt_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->json('result')->nullable();
            $table->dateTimeTz('synced_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['store_id', 'kind', 'source_id'], 'odoo_sync_records_store_kind_source_unique');
            $table->index(['status', 'next_attempt_at'], 'odoo_sync_records_status_next_index');
            $table->index(['store_id', 'status'], 'odoo_sync_records_store_id_status_index');
            $table->index(['account_id', 'store_id'], 'odoo_sync_records_account_id_store_id_index');
        });

        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'odoo_sync_records');
        Schema::dropIfExists('odoo_sync_records');
    }
};
