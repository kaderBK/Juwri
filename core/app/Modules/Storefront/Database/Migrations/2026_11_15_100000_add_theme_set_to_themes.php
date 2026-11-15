<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Register a marketplace theme's THEME SET — the vertical family it belongs
 * to (food | retail | service).
 *
 * Until now a marketplace theme had no set at all: the submission pipeline
 * filed every Liquid package under `food`, so a clothing theme was offered to
 * restaurants and never to a clothing store (a clothing store's set is
 * `retail`, per config/verticals.php). The set is derived from the theme's
 * manifest vertical — `clothing` → `retail` — and recorded on the row so the
 * catalogue can be filtered by it and the offerability rule enforced.
 *
 * Existing rows default to `food`: that is exactly where every marketplace
 * theme sat before this column existed, so nothing changes for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            if (! Schema::hasColumn('themes', 'theme_set')) {
                $table->string('theme_set', 16)->default('food')->after('category')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            if (Schema::hasColumn('themes', 'theme_set')) {
                $table->dropIndex(['theme_set']);
                $table->dropColumn('theme_set');
            }
        });
    }
};
