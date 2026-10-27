<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P3-08 (retail backend) — the Ordering module's part:
 *
 *  - `shipping_zones`: where a store ships (countries, and optionally regions / cities / postcode prefixes); the
 *    first active zone, by position, that matches an address is its zone (kit/api `ShippingQuoteResponse.zone` =
 *    the zone's `code`).
 *  - `shipping_methods`: per zone, `standard` / `express` with a carrier, a fee (minor units of currency_code, free
 *    from `free_over_minor`), transit days (`min_days`–`max_days`), an order cut-off (HH:MM, store time), the
 *    weekdays it delivers on and a tracking link template; `pickup_in_store` methods name a branch and need no zone.
 *  - `return_requests`: "Start a return" within the store's returns window. With returns refunds off (D7, the
 *    default) the resolution is an exchange or store credit; a refund only when the owner turned it on.
 *  - `orders`: the chosen shipping method and its delivery estimate (dates, store time). The shipping fee itself is
 *    the order's `delivery_fee_minor` (Order::deliveryCharge), so the total keeps its one derivation.
 *
 * Store tenant tables: account_id + store_id, store-leading keys, PostgreSQL RLS switched on here.
 */
return new class extends Migration
{
    private const NEW_TABLES = ['shipping_zones', 'shipping_methods', 'return_requests'];

    public function up(): void
    {
        if (! Schema::hasTable('shipping_zones')) {
            Schema::create('shipping_zones', function (Blueprint $table) {
                $table->id();
                $table->publicId();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->string('code', 64); // shown as `zone` (e.g. riyadh-north)
                $table->string('name', 120);
                $table->json('countries'); // ["SA", "AE"]
                $table->json('regions')->nullable();
                $table->json('cities')->nullable();
                $table->json('postal_prefixes')->nullable();
                $table->boolean('active')->default(true);
                $table->integer('position')->default(0);
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->unique(['store_id', 'code'], 'shipping_zones_store_code_unique');
                $table->index(['account_id', 'store_id'], 'shipping_zones_account_id_store_id_index');
                $table->foreign(['store_id'], 'shipping_zones_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('shipping_methods')) {
            Schema::create('shipping_methods', function (Blueprint $table) {
                $table->id();
                $table->publicId();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->unsignedBigInteger('shipping_zone_id')->nullable(); // NULL: pickup in store
                $table->checkedString('kind', ['standard', 'express', 'pickup_in_store']);
                $table->string('name', 120);
                $table->jsonb('translations')->nullable();
                $table->string('carrier', 80)->nullable();
                $table->string('tracking_url_template', 500)->nullable(); // https://…/{tracking}
                $table->bigInteger('fee_minor')->default(0);
                $table->bigInteger('free_over_minor')->nullable(); // items subtotal from which the fee is 0
                $table->char('currency_code', 3);
                $table->smallInteger('min_days')->unsigned()->default(0);
                $table->smallInteger('max_days')->unsigned()->default(0);
                $table->char('cutoff_time', 5)->nullable(); // HH:MM store time; after it the order counts from tomorrow
                $table->json('delivery_days')->nullable(); // weekdays it delivers on, 0 = Sunday … 6 = Saturday; NULL = every day
                $table->unsignedBigInteger('pickup_branch_id')->nullable();
                $table->boolean('active')->default(true);
                $table->integer('position')->default(0);
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['store_id', 'shipping_zone_id'], 'shipping_methods_store_zone_index');
                $table->index(['account_id', 'store_id'], 'shipping_methods_account_id_store_id_index');
                $table->foreign(['store_id'], 'shipping_methods_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
                $table->foreign(['shipping_zone_id'], 'shipping_methods_zone_foreign')->references(['id'])->on('shipping_zones')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('return_requests')) {
            Schema::create('return_requests', function (Blueprint $table) {
                $table->id();
                $table->publicId();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->checkedString('status', ['requested', 'approved', 'rejected', 'received', 'completed', 'cancelled'])->default('requested');
                $table->checkedString('resolution', ['exchange', 'store_credit', 'refund']);
                $table->string('reason', 500)->nullable();
                $table->json('lines'); // [{order_item_id, qty}]
                $table->bigInteger('amount_minor')->default(0); // value of the returned lines as charged
                $table->bigInteger('credited_minor')->nullable();
                $table->bigInteger('refunded_minor')->nullable();
                $table->char('currency_code', 3)->nullable();
                $table->string('owner_note', 500)->nullable();
                $table->dateTimeTz('decided_at')->nullable();
                $table->dateTimeTz('completed_at')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['store_id', 'status'], 'return_requests_store_status_index');
                $table->index(['order_id'], 'return_requests_order_id_index');
                $table->index(['account_id', 'store_id'], 'return_requests_account_id_store_id_index');
                $table->foreign(['store_id'], 'return_requests_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
                $table->foreign(['order_id'], 'return_requests_order_id_foreign')->references(['id'])->on('orders')->onDelete('cascade');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'shipping_method_id')) {
                $table->unsignedBigInteger('shipping_method_id')->nullable();
                $table->date('shipping_eta_from')->nullable();
                $table->date('shipping_eta_to')->nullable();
            }
        });

        $db = DB::connection();
        if (RowLevelSecurity::supported($db) && RowLevelSecurity::enabled()) {
            $statements = RowLevelSecurity::functionStatements();
            foreach (self::NEW_TABLES as $name) {
                $statements = array_merge($statements, RowLevelSecurity::tableStatements($name, false));
            }
            foreach ($statements as $sql) {
                $db->statement($sql);
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'shipping_method_id')) {
                $table->dropColumn(['shipping_method_id', 'shipping_eta_from', 'shipping_eta_to']);
            }
        });
        foreach (array_reverse(self::NEW_TABLES) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
