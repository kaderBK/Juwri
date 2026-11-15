<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manual order for the marketplace's theme listings.
 *
 * The listing was ordered by rating (then name) — which is right for a
 * marketplace that wants the best sellers first, and useless for an owner
 * who wants their own catalogue in a deliberate order (a flagship first, the
 * experiments last, a seasonal campaign in the middle).
 *
 * `sort_order` is that order: a small integer the owner sets per listing.
 * NULL = "no opinion" — those listings keep the rating order, after the ones
 * that do have an opinion.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('themes', 'sort_order')) {
            Schema::table('themes', function (Blueprint $table) {
                $table->unsignedInteger('sort_order')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('themes', 'sort_order')) {
            Schema::table('themes', function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }
    }
};
