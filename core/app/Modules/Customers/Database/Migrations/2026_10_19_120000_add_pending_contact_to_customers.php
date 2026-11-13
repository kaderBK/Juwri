<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-304: a customer's new phone / e-mail waits here until it is proven (SMS code / signed link); the live columns
 * keep the old, verified value until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('pending_phone', 32)->nullable();
            $table->string('pending_email', 255)->nullable();
            $table->dateTimeTz('pending_contact_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['pending_phone', 'pending_email', 'pending_contact_at']);
        });
    }
};
