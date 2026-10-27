<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-09 V-18: per-line retention windows (days) for call transcripts and recorded audio, plus the audio file
 * location and purge stamps on each call log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_lines', function (Blueprint $table) {
            $table->unsignedSmallInteger('transcript_retention_days')->default(30);
            $table->unsignedSmallInteger('audio_retention_days')->default(7);
        });

        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('audio_path', 255)->nullable();
            $table->string('audio_disk', 24)->nullable();
            $table->dateTimeTz('transcript_purged_at')->nullable();
            $table->dateTimeTz('audio_purged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn(['audio_path', 'audio_disk', 'transcript_purged_at', 'audio_purged_at']);
        });
        Schema::table('phone_lines', function (Blueprint $table) {
            $table->dropColumn(['transcript_retention_days', 'audio_retention_days']);
        });
    }
};
