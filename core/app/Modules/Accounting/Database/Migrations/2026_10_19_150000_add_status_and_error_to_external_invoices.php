<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P6-329: a failed accounting sync leaves a visible row (status 'failed' + the provider's answer), not just a failed_jobs entry. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('external_invoices', 'status')) {
            Schema::table('external_invoices', function (Blueprint $table) {
                $table->string('status', 10)->default('synced');
            });
        }
        if (! Schema::hasColumn('external_invoices', 'error')) {
            Schema::table('external_invoices', function (Blueprint $table) {
                $table->string('error', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['status', 'error'] as $column) {
            if (Schema::hasColumn('external_invoices', $column)) {
                Schema::table('external_invoices', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
