<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline POS: the source of a staff member's offline PIN verifier — PBKDF2-SHA256(PIN, own salt), written when the plain
 * PIN is at hand (set, backfill, a PIN sign-in). A till only ever gets HMAC(its own pepper, this), so the server's bcrypt
 * hash and this value never leave the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_members', function (Blueprint $table) {
            $table->char('pin_offline_salt', 32)->nullable();
            $table->char('pin_offline_kdf', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staff_members', function (Blueprint $table) {
            $table->dropColumn(['pin_offline_salt', 'pin_offline_kdf']);
        });
    }
};
