<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * P6-174: the scheduler heartbeat records whether the CLI cron or the web cron (/cronjob) ran it, so a site where only
 * the web cron runs without proc_open (module tasks and big backups skipped) can be told apart from a healthy one.
 */
class SchedulerHeartbeatSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_cli_and_web_heartbeats_are_recorded_separately(): void
    {
        scheduler_stamp_heartbeat(console: false, processes: false);
        $this->assertTrue(scheduler_alive());
        $this->assertNull(scheduler_cli_last_run_age());
        $this->assertSame(true, Cache::get('schedule_web_last_run')['in_process']);
        $this->assertTrue(scheduler_web_cron_only());

        scheduler_stamp_heartbeat(console: true);
        $this->assertSame(0, scheduler_cli_last_run_age());
        $this->assertFalse(scheduler_web_cron_only());
    }

    public function test_web_cron_with_proc_open_is_not_flagged(): void
    {
        // With proc_open the stock Schedule starts module commands as CLI child processes, so nothing is skipped.
        scheduler_stamp_heartbeat(console: false, processes: true);
        $this->assertFalse(scheduler_web_cron_only());
    }

    public function test_a_cli_cron_that_stopped_while_the_web_cron_runs_is_flagged(): void
    {
        Cache::put('schedule_cli_last_run', time() - 301, 86400);
        scheduler_stamp_heartbeat(console: false, processes: false);
        $this->assertTrue(scheduler_web_cron_only());
    }

    public function test_no_heartbeat_at_all_is_not_web_cron_only(): void
    {
        $this->assertFalse(scheduler_web_cron_only());
        Cache::put('schedule_web_last_run', ['at' => time() - 600, 'in_process' => true], 900);
        Cache::put('schedule_last_run', time() - 600, 900);
        $this->assertFalse(scheduler_web_cron_only());
    }

    public function test_the_scheduled_heartbeat_event_stamps_the_cli_marker(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'scheduler:heartbeat');
        $this->assertNotNull($event);
        $event->run($this->app); // tests run in the console, like the CLI cron
        $this->assertNotNull(scheduler_cli_last_run_age());
        $this->assertTrue(scheduler_alive());
    }
}
