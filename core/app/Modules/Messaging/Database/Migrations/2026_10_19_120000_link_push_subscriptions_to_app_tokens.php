<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-269: a native push subscription remembers the app sign-in it was registered
 * under (owner `api_tokens` row, staff `staff_tokens` row, or the driver), so
 * logout, credential changes and session revokes delete the device's push token
 * with its bearer token. A sold or stolen phone stops receiving order pushes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('api_token_id')->nullable();
            $table->unsignedBigInteger('staff_token_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->index(['api_token_id'], 'push_subscriptions_api_token_id_index');
            $table->index(['staff_token_id'], 'push_subscriptions_staff_token_id_index');
            $table->index(['driver_id'], 'push_subscriptions_driver_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropIndex('push_subscriptions_api_token_id_index');
            $table->dropIndex('push_subscriptions_staff_token_id_index');
            $table->dropIndex('push_subscriptions_driver_id_index');
            $table->dropColumn(['api_token_id', 'staff_token_id', 'driver_id']);
        });
    }
};
