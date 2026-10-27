<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-07 slice 5e: typed per-user settings (were `user_options` rows). `preferences` is the flat json map of
 * the owner's preferences; the saved login defaults are real columns (no FK on purpose: a stale default is
 * validated at read time against the owner's stores, as it always was). Invoice details live in the existing
 * `accounts.billing_profile`. Read and written only through Juwri\Core\Settings\UserSettings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('preferences')->nullable();
            $table->unsignedBigInteger('default_store_id')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['preferences', 'default_store_id', 'default_branch_id']);
        });
    }
};
