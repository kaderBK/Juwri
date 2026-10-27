<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Settings\UserSettings;

/**
 * P1-07 slice 5e: the `user_options` key/value table is retired (see UserSettings). There is no data
 * migration inside juwri (J19), so a fresh schema simply drops the empty table; if rows exist each value
 * not already stored is copied into users.preferences / the default columns / accounts.billing_profile first,
 * so nothing is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_options')) {
            return;
        }

        DB::table('user_options')->whereNotNull('user_id')->orderBy('id')->each(function ($row) {
            $user = (int) $row->user_id;
            $name = (string) $row->option_name;
            if ($name !== '' && ! UserSettings::has($user, $name)) {
                UserSettings::set($user, $name, $row->option_value);
            }
        });

        Schema::drop('user_options');
    }

    public function down(): void
    {
        Schema::create('user_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('option_name', 191)->nullable();
            $table->longText('option_value')->nullable();
            $table->index(['user_id'], 'user_options_user_id_index');
            $table->foreign(['user_id'], 'user_options_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
        });
    }
};
