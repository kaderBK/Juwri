<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-23: the platform's record of each personal-data breach notice sent to a customer (security:breach-notice) —
 * the evidence that the 48-hour (Kuwait: 24-hour) notice promised by the Terms, the DPA and the security page was
 * given. One row per (incident, customer); `deadline_at` = confirmation + the customer's notice period. A platform
 * table on purpose (no account_id / store_id tenant key): the record survives the customer's account erasure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('breach_notices')) {
            return;
        }
        Schema::create('breach_notices', function (Blueprint $table) {
            $table->id();
            $table->string('incident_ref', 40);
            $table->unsignedBigInteger('target_account_id')->nullable();
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->string('email', 255);
            $table->string('country', 2)->nullable();
            $table->unsignedSmallInteger('notice_hours');
            $table->dateTimeTz('confirmed_at');
            $table->dateTimeTz('deadline_at');
            $table->dateTimeTz('sent_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['incident_ref', 'recipient_user_id'], 'breach_notices_incident_recipient_unique');
            $table->index(['incident_ref'], 'breach_notices_incident_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breach_notices');
    }
};
