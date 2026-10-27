<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * D-P1-21-15 (J29-1): wallet_transactions (the platform wallet of a user) carried no tenant key. account_id = the
 * wallet user's account (TenantTables::USER_OWNED fills new rows the same way); a user without an account (a
 * developer/partner who owns no store, or an owner before the first store) keeps NULL until
 * TenantFill::adoptUserRows fills it. Data-preserving backfill from users.account_id. RLS policy added (PostgreSQL,
 * nullable-account predicate). Wallet movements are platform operations on an explicit user (WalletService runs
 * them outside the tenant scope: a sale credits the seller's wallet from the buyer's request).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable();
            $table->index(['account_id'], 'wallet_transactions_account_id_index');
        });

        $this->backfill();

        RowLevelSecurity::sync(DB::connection());
    }

    /** Fill the keys of rows that have none yet (idempotent; public for the regression test). */
    public function backfill(): void
    {
        DB::table('wallet_transactions')->whereNull('account_id')->update([
            'account_id' => DB::raw('(SELECT users.account_id FROM users WHERE users.id = wallet_transactions.user_id)'),
        ]);
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'wallet_transactions');
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('wallet_transactions_account_id_index');
        });
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('account_id');
        });
    }
};
