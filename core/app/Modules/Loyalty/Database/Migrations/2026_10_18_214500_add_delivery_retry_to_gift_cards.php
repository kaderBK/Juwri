<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-214: delivery retry state for gift-card e-mail (attempts, backoff, give-up).
 * Additive and nullable/defaulted: existing rows are untouched, no balance column is read or written.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gift_cards') || Schema::hasColumn('gift_cards', 'delivery_attempts')) {
            return;
        }
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->unsignedTinyInteger('delivery_attempts')->default(0);
            $table->timestamp('delivery_next_try_at')->nullable();
            $table->string('delivery_last_error', 500)->nullable();
            $table->timestamp('delivery_failed_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('gift_cards', 'delivery_attempts')) {
            return;
        }
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->dropColumn(['delivery_attempts', 'delivery_next_try_at', 'delivery_last_error', 'delivery_failed_at']);
        });
    }
};
