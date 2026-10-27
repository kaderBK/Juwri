<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reserve with Google: the store's Google Maps place ID (set by the owner), sent in the merchant feed as
 * `matching_hints.place_id` so Google matches the merchant to the right Maps listing. Nullable; one store per place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('google_place_id', 255)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['google_place_id']);
            $table->dropColumn('google_place_id');
        });
    }
};
