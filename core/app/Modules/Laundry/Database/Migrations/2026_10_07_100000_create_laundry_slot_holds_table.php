<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P3-11 (API-23): ONE 10-minute hold covers both laundry slots — the pickup (or drop-off) slot and the delivery-back
 * slot. The hold row keeps both times; capacity is counted from live holds, orders and consumed holds under the
 * store's row lock (LaundryHolds), so two holds cannot take the last place of a slot on any database. Times are UTC
 * instants (dateTimeTz); statuses are string + CHECK; the token is shown once and only its SHA-256 is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_slot_holds', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->string('mode', 24); // the order type the hold is for: pickup_slot (customer brings) | delivery (we collect + bring back)
            $table->dateTimeTz('pickup_at');
            $table->dateTimeTz('return_at')->nullable(); // delivery-back slot; null for a drop-off order
            $table->char('token_hash', 64);
            $table->char('holder_key', 64)->nullable();
            $table->checkedString('status', ['active', 'used', 'released', 'expired'], 10)->default('active');
            $table->dateTimeTz('expires_at');
            $table->unsignedBigInteger('order_id')->nullable(); // set when the hold became the order's slots
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->index(['store_id', 'status', 'expires_at'], 'laundry_slot_holds_store_id_status_expires_at_index');
            $table->index(['store_id', 'pickup_at'], 'laundry_slot_holds_store_id_pickup_at_index');
            $table->index(['store_id', 'return_at'], 'laundry_slot_holds_store_id_return_at_index');
            $table->index(['order_id'], 'laundry_slot_holds_order_id_index');
            $table->index(['holder_key', 'status'], 'laundry_slot_holds_holder_key_status_index');
            $table->index(['account_id', 'store_id'], 'laundry_slot_holds_account_id_store_id_index');
        });

        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'laundry_slot_holds');
        Schema::dropIfExists('laundry_slot_holds');
    }
};
