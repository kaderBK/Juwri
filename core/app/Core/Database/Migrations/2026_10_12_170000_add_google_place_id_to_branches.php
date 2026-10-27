<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5-25 (owner decision "Google merchant = branch"): each branch's own Google Maps place ID for the Reserve with Google
 * feeds. Nullable; a place is used by one branch of a store. Feeds fall back to stores.google_place_id for a one-branch
 * store. The branches table is already store-scoped (tenancy + RLS), so the column needs nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('google_place_id', 255)->nullable();
            $table->unique(['store_id', 'google_place_id'], 'branches_store_google_place_unique');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_store_google_place_unique');
            $table->dropColumn('google_place_id');
        });
    }
};
