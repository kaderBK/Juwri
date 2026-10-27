<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * Offline POS replay (the offline POS spec §3, OP-5):
 *
 *  pos_offline_devices  one row per till (store + the device's own id): highest sequence seen, the sequence numbers
 *                       still missing (gaps), last contact and the last measured clock skew
 *  pos_offline_records  every replayed record, keyed (store_id, client_key) — written in the SAME transaction as its
 *                       effect, so a crash between effect and answer can never apply a record twice. Final answers
 *                       (accepted / conflict / rejected) are kept, so a resend gets the same answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_offline_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('store_id')->unsigned();
            $table->bigInteger('branch_id')->unsigned()->nullable();
            $table->string('device_id', 64);
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->jsonb('missing_seqs')->nullable();
            $table->integer('clock_skew_seconds')->nullable();
            $table->dateTimeTz('last_seen_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['store_id', 'device_id'], 'pos_offline_devices_store_device_unique');
            $table->index(['account_id', 'store_id'], 'pos_offline_devices_account_id_store_id_index');
        });

        Schema::create('pos_offline_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('store_id')->unsigned();
            $table->bigInteger('branch_id')->unsigned()->nullable();
            $table->string('device_id', 64);
            $table->unsignedBigInteger('device_seq');
            $table->char('client_key', 36);
            $table->checkedString('type', ['sale', 'tender', 'void', 'drawer'], 8);
            $table->char('payload_hash', 64);
            $table->checkedString('status', ['accepted', 'conflict', 'rejected'], 10);
            $table->string('reason', 40)->nullable();
            $table->bigInteger('order_id')->unsigned()->nullable();
            $table->bigInteger('staff_id')->unsigned()->nullable();
            $table->unsignedBigInteger('snapshot_version')->nullable();
            $table->jsonb('result')->nullable();
            $table->jsonb('flags')->nullable();
            $table->dateTimeTz('device_created_at')->nullable();
            $table->dateTimeTz('business_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->unique(['store_id', 'client_key'], 'pos_offline_records_store_key_unique');
            $table->index(['store_id', 'device_id', 'device_seq'], 'pos_offline_records_device_seq_index');
            $table->index(['order_id'], 'pos_offline_records_order_id_index');
            $table->index(['account_id', 'store_id'], 'pos_offline_records_account_id_store_id_index');
        });
        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'pos_offline_records');
        RowLevelSecurity::dropTable(DB::connection(), 'pos_offline_devices');
        Schema::dropIfExists('pos_offline_records');
        Schema::dropIfExists('pos_offline_devices');
    }
};
