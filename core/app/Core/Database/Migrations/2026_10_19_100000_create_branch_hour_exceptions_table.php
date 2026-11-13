<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P6-291: per-branch date exceptions to the weekly opening hours — a holiday (closed all day) or special hours for one
 * calendar date, in the branch's own timezone. An exception overrides the weekly hours of its date. One row per
 * (branch, date). Additive: a new table, nothing existing changes. Past rows are pruned by the scheduler.
 * Tenancy: account_id + store_id like every tenant table (RLS policy from TenantTables).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_hour_exceptions')) {
            return;
        }

        Schema::create('branch_hour_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('branch_id');
            $table->date('date');
            $table->boolean('closed')->default(true);
            $table->string('open', 5)->nullable();
            $table->string('close', 5)->nullable();
            $table->string('note', 120)->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->foreign('branch_id', 'branch_hour_exceptions_branch_id_foreign')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'date'], 'branch_hour_exceptions_branch_id_date_unique');
            $table->index(['store_id', 'date'], 'branch_hour_exceptions_store_id_date_index');
            $table->index(['account_id', 'store_id'], 'branch_hour_exceptions_account_id_store_id_index');
        });

        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'branch_hour_exceptions');
        Schema::dropIfExists('branch_hour_exceptions');
    }
};
