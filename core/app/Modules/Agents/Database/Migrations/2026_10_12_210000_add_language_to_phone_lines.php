<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P6-09 V-19: language the phone agent speaks on a line (en|ar); null = the store's default language. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_lines', function (Blueprint $table) {
            $table->string('language', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phone_lines', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
