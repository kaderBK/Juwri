<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P5-28: a card refund / void the provider CONFIRMED that the POS could not book on its order (the refundable amount
 * was already used up by a cash refund, the order is no longer paid, ...). The money left the store's acquirer, so it
 * is never lost: one row per till reversal key (a resend finds it), shown to the manager on the offline-exceptions
 * screen until resolved (open → resolved, once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_card_reversal_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('store_id')->unsigned();
            $table->bigInteger('order_id')->unsigned();
            $table->char('client_uuid', 36);
            $table->char('charge_uuid', 36);
            $table->checkedString('kind', ['refund', 'void'], 8);
            $table->string('provider', 40);
            $table->string('provider_reference', 128)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('reason', 255);
            $table->bigInteger('staff_id')->unsigned()->nullable();
            $table->checkedString('state', ['open', 'resolved'], 10)->default('open');
            $table->string('resolution_note', 255)->nullable();
            $table->bigInteger('resolved_by_staff_id')->unsigned()->nullable();
            $table->dateTimeTz('resolved_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['store_id', 'client_uuid'], 'pos_card_reversal_exceptions_key_unique');
            $table->index(['store_id', 'state'], 'pos_card_reversal_exceptions_state_index');
            $table->index(['account_id', 'store_id'], 'pos_card_reversal_exceptions_account_id_store_id_index');
        });
        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'pos_card_reversal_exceptions');
        Schema::dropIfExists('pos_card_reversal_exceptions');
    }
};
