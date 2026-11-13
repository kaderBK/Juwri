<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P6-206: the per-store offline dedupe key migrates down (deduping first) and back up on every driver. */
class StaffSyncedOpsStoreKeyMigrationTest extends TestCase
{
    use DatabaseMigrations; // the test runs DDL (MySQL commits it implicitly)

    private function migration(): object
    {
        return require app_path('Modules/Team/Database/Migrations/2026_10_18_120000_scope_staff_synced_ops_op_id_to_store.php');
    }

    private function row(int $store, string $op): void
    {
        DB::table('staff_synced_ops')->insert(['store_id' => $store, 'op_id' => $op, 'status' => 'done', 'created_at' => now()]);
    }

    public function test_up_lets_two_stores_share_an_op_id_and_down_dedupes_then_restores_the_global_key(): void
    {
        // After up (the migrated schema): same op_id in two stores is fine, same store twice is refused.
        $this->row(1, 'op-a');
        $this->row(2, 'op-a');
        $this->row(1, 'op-b');
        try {
            $this->row(1, 'op-a');
            $this->fail('same store + op_id must stay unique');
        } catch (QueryException) {
        }

        $this->migration()->down();
        $this->assertSame(1, DB::table('staff_synced_ops')->where('op_id', 'op-a')->count(), 'down keeps one row per op_id');
        $this->assertSame(1, (int) DB::table('staff_synced_ops')->where('op_id', 'op-a')->value('store_id'), 'the oldest row is kept');
        $this->assertSame(1, DB::table('staff_synced_ops')->where('op_id', 'op-b')->count());
        try {
            $this->row(3, 'op-b');
            $this->fail('after down the op_id is globally unique again');
        } catch (QueryException) {
        }

        $this->migration()->up();
        $this->row(2, 'op-b');
        $this->assertSame(2, DB::table('staff_synced_ops')->where('op_id', 'op-b')->count(), 'after up two stores share op-b');
    }
}
