<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P6-54 — every scheduled order/payment/fiscal/billing job must carry an
 * onFailure alert, and a failing run must reach SchedulerAlert::fail.
 */
class SchedulerMoneyJobAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const MONEY = '/orders:|payments?|fiscal:|dunning|billing|payout|refund|invoice/i';

    private function loadedSchedule(): Schedule
    {
        $schedule = $this->app->make(Schedule::class);
        $kernel = $this->app->make(Kernel::class);
        $m = new \ReflectionMethod($kernel, 'schedule');
        $m->setAccessible(true);
        $m->invoke($kernel, $schedule);

        return $schedule;
    }

    private function afterCallbacks(Event $e): array
    {
        $ref = new \ReflectionProperty(Event::class, 'afterCallbacks');
        $ref->setAccessible(true);

        return $ref->getValue($e);
    }

    public function test_money_jobs_and_queue_drains_have_failure_callbacks(): void
    {
        $schedule = $this->loadedSchedule();
        $checked = 0;
        $missing = [];

        foreach ($schedule->events() as $event) {
            $cmd = is_string($event->command) ? $event->command : '';
            if ($cmd === '' || ! (preg_match(self::MONEY, $cmd) || str_contains($cmd, 'queue:work'))) {
                continue;
            }
            $checked++;
            if ($this->afterCallbacks($event) === []) {
                $missing[] = $cmd;
            }
        }

        $this->assertGreaterThan(0, $checked);
        $this->assertSame([], $missing, 'Scheduled money/order jobs without onFailure: '.implode(', ', $missing));
    }

    public function test_failing_run_triggers_scheduler_alert(): void
    {
        $schedule = $this->loadedSchedule();
        $event = collect($schedule->events())->first(
            fn ($e) => is_string($e->command) && str_contains($e->command, 'orders:release-scheduled')
        );
        $this->assertNotNull($event);

        // Alert sink = platform_metrics (SchedulerAlert::fail records scheduler.fail).
        $event->exitCode = 1;
        foreach ($this->afterCallbacks($event) as $cb) {
            $this->app->call($cb);
        }

        $this->assertSame(
            1,
            DB::table('platform_metrics')->where('metric', 'scheduler.fail')->where('label', 'orders:release-scheduled')->count()
        );
    }
}
