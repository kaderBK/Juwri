<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P6-09 V-11: optional owner hard cap on voice minutes per month (null = plan default from config/voice.php). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_lines', function (Blueprint $table) {
            $table->integer('monthly_minute_cap')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phone_lines', function (Blueprint $table) {
            $table->dropColumn('monthly_minute_cap');
        });
    }
};
