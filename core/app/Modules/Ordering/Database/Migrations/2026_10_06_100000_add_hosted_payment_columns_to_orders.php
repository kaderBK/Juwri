<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3-06b (kit/api README §2.12, API-2, API-10, API-21, API-27, API-28, API-32): the payment state of an order placed
 * through the headless storefront API.
 *
 *  - payment_mode: `pay_later` | `hosted` (NULL = every order placed before, or by another channel).
 *  - payment_expires_at: end of the hosted payment window (creation + 15 minutes); an order still `unpaid` then is
 *    cancelled and its stock released by `orders:expire-hosted-payments` (index for that sweep).
 *  - late_payment_status: a payment the gateway confirmed after the window (API-21) — `received_late` until the owner
 *    decides, then `accepted` (API-27), `refunded` (API-28) or `voided` (an authorised hold released / lapsed, API-32).
 *  - late_payment_minor / late_payment_at: what arrived late and when (order currency, minor units).
 *  - authorisation_expires_at: when the gateway lets an authorised card hold lapse (set by the gateway; API-32).
 *
 * Status strings with a named CHECK (checkedString; conventions §4). Nullable, no backfill: existing rows
 * keep their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'payment_mode')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->checkedString('payment_mode', ['pay_later', 'hosted'], 16)->nullable();
                $table->dateTimeTz('payment_expires_at')->nullable();
                $table->checkedString('late_payment_status', ['received_late', 'accepted', 'refunded', 'voided'], 20)->nullable();
                $table->bigInteger('late_payment_minor')->nullable();
                $table->dateTimeTz('late_payment_at')->nullable();
                $table->dateTimeTz('authorisation_expires_at')->nullable();
                $table->index(['status', 'payment_expires_at'], 'orders_status_payment_expires_idx');
                $table->index(['store_id', 'late_payment_status'], 'orders_store_late_payment_idx');
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_mode_check');
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_late_payment_status_check');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach (['orders_payment_mode_check', 'orders_late_payment_status_check'] as $check) {
                if (DB::selectOne('SELECT 1 AS x FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?', ['orders', $check]) !== null) {
                    DB::statement(\App\Support\MySqlDialect::dropCheckSql('orders', $check));
                }
            }
        }
        if (Schema::hasColumn('orders', 'payment_mode')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_status_payment_expires_idx');
                $table->dropIndex('orders_store_late_payment_idx');
                $table->dropColumn(['payment_mode', 'payment_expires_at', 'late_payment_status', 'late_payment_minor', 'late_payment_at', 'authorisation_expires_at']);
            });
        }
    }
};
