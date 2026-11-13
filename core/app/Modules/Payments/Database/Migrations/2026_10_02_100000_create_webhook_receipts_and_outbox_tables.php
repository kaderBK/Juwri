<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * J25-7: webhook idempotency + transactional outbox (platform tables, no tenant columns).
 *
 * - `webhook_receipts`: one row per (provider, event_id) a gateway webhook has verified. The row is claimed with an
 *   atomic insert-or-ignore on the unique key before the event is processed, so a re-delivered or concurrent copy of
 *   the same event is answered as a no-op. `status` processing → processed; a failed run deletes its own claim
 *   (`claim_token`) so the provider's retry runs it again; a claim stuck in processing (crashed worker) can be taken
 *   over after a few minutes. Pruned after the retention period (≥ 7 days) by `payments:prune-webhooks`.
 * - `outbox_messages`: side effects of a settled payment written in the same DB transaction as the state change and
 *   performed by the relay (inline after commit, then `payments:outbox-relay` every minute for anything left), at
 *   least once. `dedupe_key` (unique, nullable) keeps one message per intent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('event_id', 191);
            $table->string('status', 16)->default('processing');
            $table->string('claim_token', 32);
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->dateTimeTz('claimed_at');
            $table->dateTimeTz('processed_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['provider', 'event_id'], 'webhook_receipts_provider_event_id_unique');
            $table->index(['created_at'], 'webhook_receipts_created_at_index');
        });

        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100);
            $table->jsonb('payload');
            $table->string('dedupe_key', 191)->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTimeTz('available_at');
            $table->dateTimeTz('claimed_at')->nullable();
            $table->dateTimeTz('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['dedupe_key'], 'outbox_messages_dedupe_key_unique');
            $table->index(['status', 'available_at'], 'outbox_messages_status_available_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('webhook_receipts');
    }
};
