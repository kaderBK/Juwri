<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P5-17 owner-app in-app billing (Apple App Store, Google Play). User-owned (TenantTables::USER_OWNED): account_id is
 * the owner's account, NULL until the owner has one (TenantFill::adoptUserRows).
 *
 * inapp_accounts: the per-owner account token the app passes to the store at purchase (Apple appAccountToken /
 * Google obfuscatedExternalAccountId), so a store notification names whose purchase it is and a purchase can only be
 * claimed by the owner it was made for.
 * inapp_purchases: one row per store subscription (Apple originalTransactionId / Google first purchase token, kept as a
 * SHA-256 key), its owner, product, plan, state and the signed time of the last store event applied (older events are
 * ignored: a replayed or out-of-order notification never undoes a newer one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->char('token', 36);
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['user_id'], 'inapp_accounts_user_unique');
            $table->unique(['token'], 'inapp_accounts_token_unique');
            $table->index(['account_id'], 'inapp_accounts_account_id_index');
        });
        Schema::create('inapp_purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->checkedString('platform', ['apple', 'google'], 8);
            $table->string('environment', 16)->nullable();
            $table->char('original_key', 64);
            $table->string('subscription_ref', 128); // the id the billing state machine knows this subscription by
            $table->string('product_id', 191);
            $table->bigInteger('plan_id')->unsigned()->nullable();
            $table->checkedString('state', ['active', 'grace', 'cancelling', 'expired', 'refunded', 'revoked'], 12)->default('active');
            $table->string('latest_payment_id', 191)->nullable();
            $table->dateTimeTz('expires_at')->nullable();
            $table->bigInteger('last_event_ms')->default(0);
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['platform', 'original_key'], 'inapp_purchases_original_unique');
            $table->index(['user_id'], 'inapp_purchases_user_index');
            $table->index(['account_id'], 'inapp_purchases_account_id_index');
        });
        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'inapp_purchases');
        Schema::dropIfExists('inapp_purchases');
        RowLevelSecurity::dropTable(DB::connection(), 'inapp_accounts');
        Schema::dropIfExists('inapp_accounts');
    }
};
