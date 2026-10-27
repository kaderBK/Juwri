<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-036 A1a: one row per sign-in email change whose OLD address was mailed a
 * "This wasn't me — undo" link (EmailChangeUndoService). The link token is never
 * stored, only its sha256. Short explicit index names (MySQL 64-char limit with a prefix).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_change_undos')) {
            return;
        }
        Schema::create('email_change_undos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned();
            $table->string('old_email', 190);
            $table->string('new_email', 190);
            $table->string('source', 16); // self | reseller
            $table->bigInteger('reseller_id')->unsigned()->nullable();
            $table->bigInteger('actor_user_id')->unsigned()->nullable();
            $table->char('token_hash', 64);
            $table->dateTimeTz('changed_at')->nullable();
            $table->dateTimeTz('expires_at')->nullable();
            $table->dateTimeTz('used_at')->nullable();
            $table->dateTimeTz('voided_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->index(['user_id', 'used_at'], 'ecu_user_used_idx');
            $table->unique(['token_hash'], 'ecu_token_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_change_undos');
    }
};
