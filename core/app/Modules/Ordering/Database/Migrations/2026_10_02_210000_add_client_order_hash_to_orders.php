<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * J27-A2: idempotent client orders.
 *
 * The client order key itself lives in the existing `orders.client_uuid` column, already UNIQUE per store
 * (`orders_rid_cuuid_uniq`), so a resend can never insert a second row. This adds the cart fingerprint stored with
 * the first order (sha256 hex of the canonical cart) so a resend of the SAME cart replays the order while a DIFFERENT
 * cart under the same key is refused (409). Nullable: rows created before this have no fingerprint and replay as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'client_order_hash')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->char('client_order_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'client_order_hash')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('client_order_hash');
            });
        }
    }
};
