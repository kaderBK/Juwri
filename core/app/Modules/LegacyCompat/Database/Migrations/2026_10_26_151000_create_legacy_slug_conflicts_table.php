<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * J52b plan §1.1 rule 4 / §5.4 (R4): imported store slugs this install's routes would not serve as a store page (a
 * reserved word, or a newer path that matches `/{slug}` first). Written by `legacy:import` and `legacy:slug-conflicts`;
 * read (cached) at route registration so the old printed URL `/{slug}` on the old platform's host still opens the store.
 * Lives in the LegacyCompat module; empty on every install that never imports. Not a tenant table: it holds the
 * public slug only (the slug names its store), no account or store column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('legacy_slug_conflicts')) {
            return;
        }

        Schema::create('legacy_slug_conflicts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 191);
            $table->string('reason', 255);
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            // Not unique: slugs differ by case only on a case-sensitive source (`Admin` / `admin`).
            $table->index(['slug'], 'legacy_slug_conflicts_slug_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_slug_conflicts');
    }
};
