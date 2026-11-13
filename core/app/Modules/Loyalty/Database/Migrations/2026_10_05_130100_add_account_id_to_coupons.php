<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * D-P1-21-15 (J29-1): coupons carried no tenant key — an owner coupon was reached only through its owner's user_id.
 * account_id = the issuing owner's account (TenantTables::USER_OWNED fills new rows the same way). Platform and
 * reseller coupons (no user_id) keep NULL: they are not tenant data. Data-preserving: existing owner coupons are
 * backfilled from users.account_id; an owner without an account yet keeps NULL until TenantFill::adoptUserRows
 * fills it with the first store. RLS policy added for the table (PostgreSQL, nullable-account predicate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable();
            $table->index(['account_id'], 'coupons_account_id_index');
        });

        $this->backfill();

        RowLevelSecurity::sync(DB::connection());
    }

    /** Fill the keys of rows that have none yet (idempotent; public for the regression test). */
    public function backfill(): void
    {
        DB::table('coupons')->whereNotNull('user_id')->whereNull('account_id')->update([
            'account_id' => DB::raw('(SELECT users.account_id FROM users WHERE users.id = coupons.user_id)'),
        ]);
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'coupons');
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex('coupons_account_id_index');
        });
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('account_id');
        });
    }
};
