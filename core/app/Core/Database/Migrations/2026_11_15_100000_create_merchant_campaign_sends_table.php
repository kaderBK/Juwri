<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-recipient log of every merchant campaign send.
 *
 * The unique (user_id, campaign) pair makes a campaign idempotent: a second
 * run over the same segment skips the merchants it already reached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_campaign_sends', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('campaign', 64)->index();
            $table->string('status', 24)->default('queued');   // queued | sent | skipped_optout | skipped_duplicate | failed | stopped
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'campaign']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_campaign_sends');
    }
};
