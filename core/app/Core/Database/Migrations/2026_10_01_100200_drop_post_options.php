<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Settings\StoreSettings;

/**
 * P1-07 slice 5d: the `post_options` key/value table is retired — `stores.settings` (StoreSettings) is the
 * only store of per-store settings. There is no data migration inside juwri (J19), so a fresh schema
 * simply drops the empty table; if any rows exist (a database that ran the slice 1-4 mirror) each store
 * value not already in `stores.settings` is copied over first, so nothing is lost. Payment gateway names
 * are not copied (they were moved to `store_payment_gateways` when written).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('post_options')) {
            return;
        }

        DB::table('post_options')->whereNotNull('store_id')->orderBy('id')->each(function ($row) {
            $store = (int) $row->store_id;
            $name = (string) $row->option_name;
            if ($name !== '' && ! StoreSettings::has($store, $name)) {
                StoreSettings::set($store, $name, $row->option_value);
            }
        });

        Schema::drop('post_options');
    }

    public function down(): void
    {
        Schema::create('post_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('option_name', 191)->nullable();
            $table->longText('option_value')->nullable();
            $table->index(['store_id'], 'post_options_store_id_index');
            $table->index(['account_id', 'store_id'], 'post_options_account_id_store_id_index');
            $table->foreign(['store_id'], 'post_options_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
        });
    }
};
