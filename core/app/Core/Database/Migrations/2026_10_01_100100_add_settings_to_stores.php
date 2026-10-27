<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-07: `stores.settings` — the typed per-store settings tree (was ~190 `post_options` key/value rows
 * per store). Read and written only through Juwri\Core\Settings\StoreSettings; the layout is
 * `{"<group>": {"<name>": value}}` per StoreSettingKeys. Payment gateway settings are not here
 * (`store_payment_gateways`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->jsonb('settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
