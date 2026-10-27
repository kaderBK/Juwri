<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * Lane C bug fix (2026-10-06): a deposit refund the gateway layer refused while a booking was being cancelled.
 * Before, the booking stayed cancelled with the deposit paid and nobody was told. Now one row per booking records the
 * refund as pending (UNIQUE(booking_id): recorded once, retried on the same row): the owner dashboard lists it and
 * the bookings page offers "Retry refund". `status`: pending → done (refunded through DepositService::refund).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_pending_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->bigInteger('amount_minor')->default(0);
            $table->string('currency', 8);
            $table->checkedString('status', ['pending', 'done'], 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->string('last_error', 255)->nullable();
            $table->dateTimeTz('last_attempt_at')->nullable();
            $table->dateTimeTz('resolved_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['booking_id'], 'booking_pending_refunds_booking_id_unique');
            $table->index(['store_id', 'status'], 'booking_pending_refunds_store_id_status_index');
            $table->index(['account_id', 'store_id'], 'booking_pending_refunds_account_id_store_id_index');
        });

        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'booking_pending_refunds');
        Schema::dropIfExists('booking_pending_refunds');
    }
};
