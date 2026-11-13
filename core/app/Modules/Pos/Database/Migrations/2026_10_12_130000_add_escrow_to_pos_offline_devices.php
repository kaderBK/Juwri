<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline POS: the till's escrow key (32 random bytes, encrypted with APP_KEY). The browser till wraps its data key with
 * it, so after a reload ONLINE it opens its encrypted offline store for a signed-in staff session without a PIN. Handed
 * only to the registered, non-revoked till itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_offline_devices', function (Blueprint $table) {
            $table->text('escrow')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_offline_devices', function (Blueprint $table) {
            $table->dropColumn('escrow');
        });
    }
};
