<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * Offline POS (the offline POS spec §2): the catalogue snapshot a till keeps for selling offline.
 * One row per (store, branch, version); `version` rises by one each time the snapshot's content (hash) changes, so a
 * replayed offline sale can be re-priced with exactly the snapshot it was sold with. `branch_id` 0 = a store without
 * branches. Kept 30 days (the latest version always stays).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_offline_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('store_id')->unsigned();
            $table->bigInteger('branch_id')->unsigned()->default(0);
            $table->unsignedBigInteger('version');
            $table->char('hash', 64);
            $table->longText('payload');
            $table->dateTimeTz('created_at')->nullable();
            $table->unique(['store_id', 'branch_id', 'version'], 'pos_offline_snapshots_version_unique');
            $table->index(['account_id', 'store_id'], 'pos_offline_snapshots_account_id_store_id_index');
        });
        RowLevelSecurity::sync(DB::connection()); // PostgreSQL: the tenant policy for the new table (no-op elsewhere)
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'pos_offline_snapshots');
        Schema::dropIfExists('pos_offline_snapshots');
    }
};
