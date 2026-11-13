<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * D-P1-21-15 (J29-1): supplier_payments carried no tenant key — a payment was reached only through its supplier.
 * It now carries store_id + account_id like every store row, copied from the supplier (TenantTables::PARENTS fills
 * new rows the same way). Data-preserving: existing rows are backfilled from their supplier; a payment whose supplier
 * no longer exists keeps NULL keys (nullable, never dropped). RLS policy added for the table (PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable();
            $table->bigInteger('store_id')->unsigned()->nullable();
            $table->index(['account_id', 'store_id'], 'supplier_payments_account_id_store_id_index');
            $table->index(['store_id'], 'supplier_payments_store_id_index');
        });

        $this->backfill();

        RowLevelSecurity::sync(DB::connection());
    }

    /** Fill the keys of rows that have none yet (idempotent; public for the regression test). */
    public function backfill(): void
    {
        DB::table('supplier_payments')->whereNull('account_id')->update([
            'store_id' => DB::raw('(SELECT suppliers.store_id FROM suppliers WHERE suppliers.id = supplier_payments.supplier_id)'),
            'account_id' => DB::raw('(SELECT suppliers.account_id FROM suppliers WHERE suppliers.id = supplier_payments.supplier_id)'),
        ]);
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'supplier_payments');
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropIndex('supplier_payments_account_id_store_id_index');
            $table->dropIndex('supplier_payments_store_id_index');
        });
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn(['account_id', 'store_id']);
        });
    }
};
