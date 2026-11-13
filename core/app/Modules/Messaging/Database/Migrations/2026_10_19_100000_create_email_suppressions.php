<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-249: addresses the mail provider reported as hard-bounced or as a spam complaint. Platform-wide (one sender
 * reputation for every store), so no account_id / store_id. Bulk mail (campaigns, digests) skips these addresses;
 * transactional mail still goes out and the owner sees the address flagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email', 254)->unique();
            $table->checkedString('reason', ['bounce', 'complaint'], 16);
            $table->string('provider', 16);
            $table->string('detail', 255)->nullable();
            $table->unsignedInteger('events')->default(1);
            $table->dateTimeTz('last_event_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
