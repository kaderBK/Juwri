<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * O5 — scheduler-dead heartbeat. The scheduler stamps schedule_last_run every
 * minute; scheduler_alive() reads it, and /healthz surfaces it (soft/degraded,
 * never a hard 503 on its own).
 */
class SchedulerHeartbeatTest extends TestCase
{
    public function test_fresh_stamp_is_alive(): void
    {
        Cache::put('schedule_last_run', time(), 900);

        $this->assertTrue(scheduler_alive());
        $this->assertLessThanOrEqual(2, scheduler_last_run_age());
    }

    public function test_stale_stamp_is_dead(): void
    {
        Cache::put('schedule_last_run', time() - 400, 900);

        $this->assertFalse(scheduler_alive());
        $this->assertGreaterThanOrEqual(300, scheduler_last_run_age());
    }

    public function test_never_run_is_null_age(): void
    {
        Cache::forget('schedule_last_run');

        $this->assertNull(scheduler_last_run_age());
        $this->assertFalse(scheduler_alive());
    }

    public function test_healthz_reports_scheduler_and_stays_200_when_stale(): void
    {
        Cache::put('schedule_last_run', time(), 900);
        $this->getJson('/healthz')->assertOk()->assertJsonPath('checks.scheduler', 'ok');

        // A stale scheduler is degraded but must not flip the overall probe to 503.
        Cache::put('schedule_last_run', time() - 400, 900);
        $this->getJson('/healthz')->assertOk()->assertJsonPath('checks.scheduler', 'degraded');
    }
}
