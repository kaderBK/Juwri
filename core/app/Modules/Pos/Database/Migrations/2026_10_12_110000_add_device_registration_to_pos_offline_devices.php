<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline POS device registration (OP-2): a till registers once (token kept as a SHA-256 hash, a per-device pepper for
 * the offline PIN check kept encrypted with APP_KEY), the owner can revoke it, and the till reports when it wiped
 * itself. (The staff offline PIN source is a Team migration.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_offline_devices', function (Blueprint $table) {
            $table->string('name', 80)->nullable();
            $table->string('platform', 20)->nullable();
            $table->char('token_hash', 64)->nullable();
            $table->text('pepper')->nullable();
            $table->bigInteger('registered_by_staff_id')->unsigned()->nullable();
            $table->dateTimeTz('registered_at')->nullable();
            $table->dateTimeTz('revoked_at')->nullable();
            $table->bigInteger('revoked_by_user_id')->unsigned()->nullable();
            $table->dateTimeTz('wiped_at')->nullable();
            $table->unique(['token_hash'], 'pos_offline_devices_token_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pos_offline_devices', function (Blueprint $table) {
            $table->dropUnique('pos_offline_devices_token_unique');
            $table->dropColumn(['name', 'platform', 'token_hash', 'pepper', 'registered_by_staff_id', 'registered_at', 'revoked_at', 'revoked_by_user_id', 'wiped_at']);
        });
    }
};
