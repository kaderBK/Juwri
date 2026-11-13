<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core — the cuisine taxonomy and the store–cuisine link.
 *
 * P1-13 13d follow-up: Community code reads the cuisine taxonomy (store settings picker, storefront JSON-LD
 * servesCuisine, Bio generator), which the ee Marketplace migration used to create — a Community install without
 * the Enterprise package had no such tables. Same columns and indexes as before (P1-03 squashed schema). Idempotent: an install
 * where the Marketplace migration already created them skips the create. Timestamped before the tenant RLS migration
 * (2026_10_01_900000) so a fresh PostgreSQL install protects them too.
 *
 * Tables: cuisines, cuisine_store
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cuisines')) {
            Schema::create('cuisines', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->string('slug', 80);
                $table->string('icon', 64)->nullable();
                $table->integer('position')->unsigned()->default(0);
                $table->boolean('active')->default(true);
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['active', 'position'], 'cuisines_active_position_index');
                $table->unique(['slug'], 'cuisines_slug_unique');
            });
        }

        if (! Schema::hasTable('cuisine_store')) {
            Schema::create('cuisine_store', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->bigInteger('cuisine_id')->unsigned();
                $table->bigInteger('store_id')->unsigned();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->unique(['cuisine_id', 'store_id'], 'cuisine_store_cuisine_id_store_id_unique');
                $table->index(['store_id'], 'cuisine_store_store_id_index');
                $table->index(['account_id', 'store_id'], 'cuisine_store_account_id_store_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cuisine_store');
        Schema::dropIfExists('cuisines');
    }
};
