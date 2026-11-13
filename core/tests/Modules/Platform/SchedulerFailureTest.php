<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Support\SchedulerAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * O3 — scheduler failure alerting. Failure hooks record a scheduler.fail metric
 * and log, the clean-verify stamp tracks the Retire gate, and the critical
 * revenue/integrity tasks carry an onFailure callback.
 */
class SchedulerFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_fail_logs_and_records_metric(): void
    {
        Log::spy();

        SchedulerAlert::fail('subscriptions:verify');

        Log::shouldHaveReceived('channel')->atLeast()->once();
        $this->assertSame(
            1,
            DB::table('platform_metrics')->where('metric', 'scheduler.fail')->where('label', 'subscriptions:verify')->count()
        );
    }

    public function test_verify_clean_stamp_roundtrip(): void
    {
        SchedulerAlert::markVerifyClean();
        $this->assertSame(now()->toDateString(), SchedulerAlert::lastCleanVerify());

        SchedulerAlert::markVerifyFailed();
        $this->assertNull(SchedulerAlert::lastCleanVerify());
    }

    public function test_critical_tasks_have_failure_hooks(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $schedule = $this->app->make(Schedule::class);
        $kernel = $this->app->make(\App\Console\Kernel::class);
        $method = new \ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        $targets = [
            'subscriptions:verify', 'ledger:reconcile', 'reseller:invoice',
            'subscriptions:tick', 'backups:run-scheduled',
        ];

        $ref = new \ReflectionProperty(\Illuminate\Console\Scheduling\Event::class, 'afterCallbacks');
        $ref->setAccessible(true);

        foreach ($targets as $cmd) {
            $event = collect($schedule->events())->first(
                fn ($e) => is_string($e->command) && str_contains($e->command, $cmd)
            );
            $this->assertNotNull($event, "scheduled event for {$cmd} not found");
            $this->assertNotEmpty($ref->getValue($event), "{$cmd} must have a failure/after callback");
        }
    }
}
