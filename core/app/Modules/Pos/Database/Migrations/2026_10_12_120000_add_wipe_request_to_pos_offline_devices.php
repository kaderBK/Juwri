<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Offline POS: the owner asks a till that stays in service to wipe itself (uploads first; the request is kept for audit). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_offline_devices', function (Blueprint $table) {
            $table->dateTimeTz('wipe_requested_at')->nullable();
            $table->bigInteger('wipe_requested_by_user_id')->unsigned()->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_offline_devices', function (Blueprint $table) {
            $table->dropColumn(['wipe_requested_at', 'wipe_requested_by_user_id']);
        });
    }
};
