<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-09 V-10: recording is off unless the owner picks a region policy AND turns it on; a call records only
 * after the caller's spoken yes (call_logs.consent / call_logs.recording).
 * P6-09 V-14: link-pay-only switch per line, pay mode per call, caller-ID blocklist per store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_lines', function (Blueprint $table) {
            $table->boolean('recording_enabled')->default(false);
            $table->string('recording_region', 8)->nullable();
            $table->boolean('link_pay_only')->default(false);
        });

        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('consent', 8)->nullable();
            $table->boolean('recording')->default(false);
            $table->string('pay_mode', 8)->nullable();
        });

        Schema::create('voice_caller_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('store_id')->unsigned();
            $table->string('number', 32);
            $table->string('reason', 191)->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['store_id', 'number'], 'voice_caller_blocks_store_number_unique');
            $table->index(['account_id', 'store_id'], 'voice_caller_blocks_account_id_store_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_caller_blocks');
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn(['consent', 'recording', 'pay_mode']);
        });
        Schema::table('phone_lines', function (Blueprint $table) {
            $table->dropColumn(['recording_enabled', 'recording_region', 'link_pay_only']);
        });
    }
};
