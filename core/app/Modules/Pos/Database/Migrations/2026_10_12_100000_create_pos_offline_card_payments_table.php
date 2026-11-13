<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * Offline POS (offline card, OP-7): a card payment a till took offline through the provider SDK's deferred
 * authorisation. No card data — the provider reference, the masked PAN / scheme the provider reports, the caps in force
 * when it was taken, and its state: pending_offline (never counted as paid) → captured | declined_after_offline →
 * resolved (the manager collected it another way; the declined record stays).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_offline_card_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('store_id')->unsigned();
            $table->bigInteger('branch_id')->unsigned()->nullable();
            $table->bigInteger('order_id')->unsigned();
            $table->char('client_key', 36);
            $table->string('device_id', 64);
            $table->string('provider', 40);
            $table->string('provider_reference', 128);
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->bigInteger('cap_amount_minor');
            $table->bigInteger('cap_total_minor');
            $table->checkedString('state', ['pending_offline', 'captured', 'declined_after_offline', 'resolved'], 24)->default('pending_offline');
            $table->string('masked_pan', 32)->nullable();
            $table->string('scheme', 24)->nullable();
            $table->string('resolution_method', 20)->nullable();
            $table->bigInteger('resolved_by_staff_id')->unsigned()->nullable();
            $table->dateTimeTz('taken_at')->nullable();
            $table->dateTimeTz('decided_at')->nullable();
            $table->dateTimeTz('resolved_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['store_id', 'client_key'], 'pos_offline_card_payments_key_unique');
            $table->unique(['provider', 'provider_reference'], 'pos_offline_card_payments_ref_unique');
            $table->index(['store_id', 'state'], 'pos_offline_card_payments_state_index');
            $table->index(['store_id', 'device_id', 'state'], 'pos_offline_card_payments_device_index');
            $table->index(['account_id', 'store_id'], 'pos_offline_card_payments_account_id_store_id_index');
        });
        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'pos_offline_card_payments');
        Schema::dropIfExists('pos_offline_card_payments');
    }
};
