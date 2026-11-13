<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-224: deliveries (purchase orders), stock counts and waste carry the branch they happened at, so their stock
 * movements hit that branch's balance (branch_stock_levels) like sales already do. Nullable and additive: existing
 * rows stay store-wide (NULL), safe on a populated database.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['purchase_orders', 'stock_counts', 'waste_logs'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            if (Schema::hasColumn($name, 'branch_id')) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->bigInteger('branch_id')->unsigned()->nullable();
                $table->index(['store_id', 'branch_id'], $name.'_store_id_branch_id_index');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            if (! Schema::hasColumn($name, 'branch_id')) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_store_id_branch_id_index');
                $table->dropColumn('branch_id');
            });
        }
    }
};
