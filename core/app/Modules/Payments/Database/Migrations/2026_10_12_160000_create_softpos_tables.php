<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P5-21 KSA SoftPOS (Geidea, EdfaPay) through each store's own acquirer account.
 *
 * terminal_accounts: one provider account per store (merchant id + encrypted credentials: API key / password, callback
 * secret). Reset on an ownership transfer (the new owner connects their own acquirer).
 * terminal_payments: every charge, refund and void, keyed by the client's UUID per store (the idempotency key: the same
 * key is the same operation, never a second one), with the provider reference and state. No card data: masked PAN,
 * scheme and auth code only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('store_id')->unsigned();
            $table->checkedString('provider', ['geidea_softpos', 'edfapay_softpos'], 20);
            $table->boolean('enabled')->default(false);
            $table->string('merchant_id', 128);
            $table->text('credentials'); // encrypted
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['store_id', 'provider'], 'terminal_accounts_store_provider_unique');
            $table->index(['account_id', 'store_id'], 'terminal_accounts_account_id_store_id_index');
        });
        Schema::create('terminal_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('store_id')->unsigned();
            $table->checkedString('provider', ['geidea_softpos', 'edfapay_softpos'], 20);
            $table->checkedString('kind', ['charge', 'refund', 'void'], 8);
            $table->char('client_uuid', 36);
            $table->bigInteger('parent_id')->unsigned()->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->checkedString('state', ['creating', 'pending', 'authorised', 'captured', 'declined', 'voided', 'refunded', 'failed'], 12)->default('creating');
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->bigInteger('refunded_minor')->default(0);
            $table->string('masked_pan', 32)->nullable();
            $table->string('scheme', 24)->nullable();
            $table->string('auth_code', 16)->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['store_id', 'client_uuid'], 'terminal_payments_store_key_unique');
            $table->index(['store_id', 'provider', 'provider_reference'], 'terminal_payments_reference_index');
            $table->index(['parent_id'], 'terminal_payments_parent_index');
            $table->index(['account_id', 'store_id'], 'terminal_payments_account_id_store_id_index');
        });
        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'terminal_payments');
        Schema::dropIfExists('terminal_payments');
        RowLevelSecurity::dropTable(DB::connection(), 'terminal_accounts');
        Schema::dropIfExists('terminal_accounts');
    }
};
