<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 item 18: the Moyasar (KSA) gateway row — off until the platform admin switches it on with its keys. Stores
 * switch it on for their own customers in their payment settings. Inserted once (re-running finds it).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_gateways') || DB::table('payment_gateways')->where('payment_folder', 'moyasar')->exists()) {
            return;
        }
        DB::table('payment_gateways')->insert(['payment_install' => false, 'payment_title' => 'Moyasar', 'payment_folder' => 'moyasar']);
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_gateways')) {
            DB::table('payment_gateways')->where('payment_folder', 'moyasar')->delete();
        }
    }
};
