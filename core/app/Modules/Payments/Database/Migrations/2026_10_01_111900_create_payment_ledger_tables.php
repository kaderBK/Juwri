<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-13 13g: the payments ledger — every Transaction (a business's own customer payments as well as platform purchases)
 * and its durable receipt (BillingReceipt) — is Payments' (Community); the tables came with the Billing migration. Same
 * columns, indexes and foreign keys; Schema::hasTable guards keep it a no-op where they exist. Before Billing's
 * migration (112000) and the tenant RLS migration (900000).
 *
 * Tables: billing_receipts, transaction
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_receipts')) {
            Schema::create('billing_receipts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->bigInteger('user_id')->unsigned()->nullable();
                $table->unsignedBigInteger('store_id')->nullable(); // copied from the transaction (order payments)
                $table->bigInteger('transaction_id')->unsigned()->nullable();
                $table->string('transaction_method', 20)->nullable();
                $table->string('product_name', 225)->nullable();
                $table->bigInteger('product_id')->nullable();
                $table->bigInteger('plan_id')->nullable();
                $table->string('addon_type', 255)->nullable();
                $table->bigInteger('amount_minor')->default(0); // P1-05: minor units of currency_code
                $table->bigInteger('base_amount_minor')->nullable(); // P1-05
                $table->char('currency_code', 3)->nullable();
                $table->string('gateway', 255)->nullable();
                $table->string('gateway_ref', 255)->nullable();
                $table->text('details')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['currency_code'], 'billing_receipts_currency_code_index');
                $table->unique(['transaction_id'], 'billing_receipts_transaction_id_unique');
                $table->index(['user_id', 'created_at'], 'billing_receipts_user_id_created_at_index');
                $table->index(['user_id'], 'billing_receipts_user_id_index');
                $table->index(['account_id'], 'billing_receipts_account_id_index');
                $table->index(['store_id', 'created_at'], 'billing_receipts_store_id_created_at_index');
            });
        }

        if (! Schema::hasTable('transaction')) {
            Schema::create('transaction', function (Blueprint $table) {
                $table->id();
                $table->publicId();
                $table->unsignedBigInteger('account_id')->nullable();
                // P1-10/P1-04: the store an order payment (transaction_method 'order') belongs to. user_id is only ever a
                // users.id (the paying owner: plans, add-ons, top-ups, …); it is NULL on order payments (the diner is a
                // customer, not a user).
                $table->unsignedBigInteger('store_id')->nullable();
                $table->string('invoice_number', 32)->nullable();
                $table->string('product_name', 225)->nullable();
                $table->bigInteger('product_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('status', 20)->nullable();
                $table->dateTimeTz('credited_at')->nullable();
                $table->bigInteger('amount_minor')->nullable(); // P1-05: minor units of currency_code
                $table->bigInteger('base_amount_minor')->nullable(); // P1-05 (pre-tax, same currency)
                $table->string('currency_code', 3)->nullable();
                $table->string('payment_id', 255)->nullable();
                $table->string('transaction_gatway', 255)->nullable();
                $table->string('transaction_method', 20)->nullable();
                $table->string('transaction_description', 255)->nullable();
                $table->string('transaction_ip', 100)->nullable();
                $table->checkedString('frequency', ['MONTHLY', 'YEARLY', 'LIFETIME'])->nullable();
                $table->jsonb('billing')->nullable();
                $table->text('taxes_ids')->nullable();
                $table->text('details')->nullable();
                $table->jsonb('coupon')->nullable();
                $table->checkedString('featured', ['0', '1'])->nullable()->default('0');
                $table->checkedString('urgent', ['0', '1'])->nullable()->default('0');
                $table->checkedString('highlight', ['0', '1'])->nullable()->default('0');
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->unique(['invoice_number'], 'transaction_invoice_number_unique');
                $table->unique(['payment_id', 'transaction_gatway'], 'transaction_payment_gateway_unique');
                $table->index(['status', 'created_at'], 'transaction_status_created_idx');
                $table->index(['user_id'], 'transaction_user_id_index');
                $table->index(['account_id'], 'transaction_account_id_index');
                $table->index(['store_id', 'transaction_method', 'status'], 'transaction_store_method_status_index');
                $table->foreign(['user_id'], 'transaction_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
                // Payment history outlives a deleted store (set null, never cascade).
                $table->foreign(['store_id'], 'transaction_store_id_foreign')->references(['id'])->on('stores')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction');
        Schema::dropIfExists('billing_receipts');
    }
};
