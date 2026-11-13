<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-158: duplicate-send marker for outbox side effects that cannot be rolled back (a gift-card email, a paid-order
 * print). One row per (message type, entity key), written in its own autocommit statement BEFORE the send, so a job
 * killed after the send but before its commit/ack is re-run without sending again. `sending` = claimed, outcome
 * unknown (kill); `sent` = confirmed. A send that fails cleanly releases (deletes) its row so it can be retried.
 * Platform table, no tenant columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_send_markers', function (Blueprint $table) {
            $table->id();
            $table->string('message_type', 100);
            $table->string('entity_key', 191);
            $table->string('state', 16)->default('sending');
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['message_type', 'entity_key'], 'outbox_send_markers_type_entity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_send_markers');
    }
};
