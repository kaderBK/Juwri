<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-21 part 2 (J29-5): the platform's record of store-data operations (export, delete, restore) on one account or
 * store. No personal data: the target ids, the acting superadmin's user id, row counts per table. A platform table on
 * purpose (no account_id / store_id): an account's erasure keeps this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_data_operations', function (Blueprint $table) {
            $table->id();
            $table->checkedString('operation', ['export', 'delete', 'restore'], 16);
            $table->checkedString('scope_type', ['account', 'store'], 16);
            $table->unsignedBigInteger('target_account_id')->nullable();
            $table->unsignedBigInteger('target_store_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor', 32)->default('console');
            $table->jsonb('report')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->index(['target_account_id'], 'store_data_operations_target_account_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_data_operations');
    }
};
