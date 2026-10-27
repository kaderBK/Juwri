<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-241: the daily DNS recheck of verified custom domains (storefront:domains-recheck). `dns_checked_at` is the last
 * check; `dns_failing_since` is when the domain stopped pointing at the platform (null while it passes). After
 * hosts.release_after_days of continuous failure the row is released so the host's new owner can claim it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_domains', function (Blueprint $table) {
            if (! Schema::hasColumn('store_domains', 'dns_checked_at')) {
                $table->dateTimeTz('dns_checked_at')->nullable();
            }
            if (! Schema::hasColumn('store_domains', 'dns_failing_since')) {
                $table->dateTimeTz('dns_failing_since')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_domains', function (Blueprint $table) {
            $table->dropColumn(['dns_checked_at', 'dns_failing_since']);
        });
    }
};
