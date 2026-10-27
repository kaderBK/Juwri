<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-220: after a plan downgrade/expiry the branches and staff above the new plan's limit are switched off
 * (active = false) — never deleted. `plan_suspended_at` marks rows switched off BY THE PLAN (not by the owner), so an
 * upgrade switches exactly those back on. Nullable, no backfill: existing rows were never plan-suspended.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['branches', 'staff_members'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'plan_suspended_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dateTimeTz('plan_suspended_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['branches', 'staff_members'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'plan_suspended_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('plan_suspended_at');
                });
            }
        }
    }
};
