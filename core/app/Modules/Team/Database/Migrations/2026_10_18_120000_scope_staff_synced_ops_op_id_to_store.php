<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-206: the staff PWA offline dedupe key is per store — two stores' devices may mint
 * the same client_op_id, and one must not swallow the other's write.
 * Up only widens the key, so existing rows always fit. Down narrows it back: rows that share an op_id across stores
 * (possible only after up) are deduped first, keeping the oldest row per op_id (they are replay markers, not money).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_synced_ops', function (Blueprint $table) {
            $table->dropUnique('staff_synced_ops_op_id_unique');
            $table->unique(['store_id', 'op_id'], 'staff_synced_ops_store_id_op_id_unique');
        });
    }

    public function down(): void
    {
        $keep = DB::table('staff_synced_ops')->selectRaw('MIN(id) as id')->groupBy('op_id');
        $dupes = DB::table('staff_synced_ops')->whereNotIn('id', DB::query()->fromSub($keep, 'k')->select('id'))->pluck('id');
        foreach ($dupes->chunk(500) as $chunk) {
            DB::table('staff_synced_ops')->whereIn('id', $chunk->all())->delete();
        }
        Schema::table('staff_synced_ops', function (Blueprint $table) {
            $table->dropUnique('staff_synced_ops_store_id_op_id_unique');
            $table->unique(['op_id'], 'staff_synced_ops_op_id_unique');
        });
    }
};
