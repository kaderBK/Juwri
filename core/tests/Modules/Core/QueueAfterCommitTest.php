<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Ordering\Jobs\SendStaffOrderPushJob;
use Tests\TestCase;

/**
 * P6-64: with after_commit on the database queue, a job dispatched inside DB::transaction is only
 * pushed once the transaction commits, and is never pushed when it rolls back.
 * RefreshDatabase is test-transaction aware: the test's own DB::transaction counts as the outermost one.
 */
class QueueAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    public function test_job_dispatched_in_a_transaction_is_pushed_only_after_commit(): void
    {
        DB::transaction(function () {
            SendStaffOrderPushJob::dispatch(1, ['order_id' => 1]);
            $this->assertSame(0, DB::table('jobs')->count(), 'job pushed before commit');
        });

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_job_dispatched_in_a_rolled_back_transaction_is_never_pushed(): void
    {
        try {
            DB::transaction(function () {
                SendStaffOrderPushJob::dispatch(1, ['order_id' => 1]);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, DB::table('jobs')->count());
    }
}
