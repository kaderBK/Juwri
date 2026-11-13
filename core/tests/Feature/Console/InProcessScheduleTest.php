<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Console;

use App\Console\InProcessSchedule;
use App\Console\Kernel;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Support\RequestEpoch;
use Tests\TestCase;

/**
 * P6-134: on a host without proc_open (shared hosting) `schedule:run` could not start any scheduled command
 * ("The Process class relies on proc_open"): no queue drain, no backup, nothing. The Kernel then schedules the commands
 * in-process. With proc_open the stock Schedule is untouched.
 */
class InProcessScheduleTest extends TestCase
{
    private function kernel(bool $processes): Kernel
    {
        return new class($this->app, $this->app->make(Dispatcher::class), $processes) extends Kernel
        {
            public function __construct(Application $app, Dispatcher $events, private bool $processes)
            {
                parent::__construct($app, $events);
            }

            protected function processesAvailable(): bool
            {
                return $this->processes;
            }
        };
    }

    public function test_without_proc_open_every_scheduled_command_is_an_in_process_callback(): void
    {
        $schedule = $this->kernel(false)->resolveConsoleSchedule();

        $this->assertInstanceOf(InProcessSchedule::class, $schedule);
        $names = [];
        foreach ($schedule->events() as $event) {
            $this->assertInstanceOf(CallbackEvent::class, $event, 'no event may need a child process: '.($event->command ?? ''));
            $names[] = (string) $event->description;
        }
        $this->assertContains('scheduler:heartbeat', $names);
        $this->assertTrue(
            (bool) array_filter($names, fn (string $n) => str_starts_with($n, 'queue:work --stop-when-empty --max-time=')),
            'the default queue drain is scheduled',
        );
        $this->assertTrue(
            (bool) array_filter($names, fn (string $n) => str_starts_with($n, 'queue:work heavy --queue=heavy --stop-when-empty')),
            'the heavy queue drain is scheduled',
        );
        $this->assertContains('platform:db-backup', $names);
    }

    public function test_with_proc_open_the_stock_schedule_is_used(): void
    {
        $schedule = $this->kernel(true)->resolveConsoleSchedule();

        $this->assertNotInstanceOf(InProcessSchedule::class, $schedule);
        $this->assertInstanceOf(Schedule::class, $schedule);
        $commands = array_filter($schedule->events(), fn ($e) => ! $e instanceof CallbackEvent);
        $this->assertNotEmpty($commands, 'artisan commands are started as processes as before');
    }

    public function test_a_command_runs_inside_the_process_and_its_arguments_arrive(): void
    {
        $seen = [];
        Artisan::command('drill:inproc {name} {--flag} {--times=1}', function (string $name) use (&$seen) {
            $seen = [$name, $this->option('flag'), $this->option('times')];
        });

        $failed = false;
        $event = (new InProcessSchedule)->command('drill:inproc --flag', ['abc', '--times' => 3])
            ->withoutOverlapping(5)->onFailure(function () use (&$failed) {
                $failed = true;
            });
        $event->run($this->app);

        $this->assertSame(['abc', true, '3'], $seen);
        $this->assertFalse($failed);
        $this->assertSame(0, $event->exitCode);
    }

    public function test_a_drain_run_in_process_never_closes_the_daemon_gate(): void
    {
        config(['queue.default' => 'database']);
        Cache::forget('queue_worker_daemon_alive');
        Artisan::command('drill:drain {--stop-when-empty}', function () {
            queue_worker_stamp_heartbeat(['artisan', 'schedule:run']); // the argv a drain has inside schedule:run
        });
        Artisan::command('drill:daemon', function () {
            queue_worker_stamp_heartbeat(['artisan', 'schedule:run']);
        });

        (new InProcessSchedule)->command('drill:drain --stop-when-empty')->run($this->app);
        $this->assertFalse(queue_daemon_alive(), 'a --stop-when-empty drain is a fallback drain even inside schedule:run');
        $this->assertFalse($this->app->bound('queue.fallback_drain'), 'the flag does not leak past the command');

        (new InProcessSchedule)->command('drill:daemon')->run($this->app);
        $this->assertTrue(queue_daemon_alive(), 'a command without --stop-when-empty is not marked');
    }

    public function test_web_cron_skips_commands_it_cannot_see_and_keeps_drains_inside_the_request_budget(): void
    {
        config(['cron.max_seconds' => 50]);
        $seen = null;
        Artisan::command('drill:webdrain {--stop-when-empty} {--max-time=1800}', function () use (&$seen) {
            $seen = $this->option('max-time');
        });
        $web = new class extends InProcessSchedule
        {
            protected function webContext(): bool
            {
                return true;
            }
        };

        $web->command('drill:webdrain --stop-when-empty --max-time=1800')->run($this->app);
        $this->assertSame('40', $seen, 'a 1800 s drain is clamped to the request budget minus 10 s');

        $seen = null;
        $web->command('drill:webdrain --stop-when-empty --max-time=20')->run($this->app);
        $this->assertSame('20', $seen, 'a short drain is left alone');

        $event = $web->command('drill:not-registered-in-web')->onFailure(fn () => $this->fail('a skipped command is not a failure'));
        $event->run($this->app);
        $this->assertSame(0, $event->exitCode, 'unregistered module command: skipped with a warning, not an error');
    }

    public function test_the_web_cron_skip_warning_is_logged_once_per_run_and_again_in_the_next_run(): void
    {
        $web = new class extends InProcessSchedule
        {
            protected function webContext(): bool
            {
                return true;
            }
        };
        $warnings = 0;
        Log::listen(function ($e) use (&$warnings) {
            if ($e->message === 'schedule.skipped_in_web_cron') {
                $warnings++;
            }
        });

        RequestEpoch::bump();
        $web->command('drill:skip-one')->run($this->app);
        $web->command('drill:skip-two')->run($this->app);
        $this->assertSame(1, $warnings, 'one warning per web-cron run');

        RequestEpoch::bump(); // next request in the same (Octane) process
        $web->command('drill:skip-one')->run($this->app);
        $this->assertSame(2, $warnings, 'a later run in the same process warns again');
    }

    public function test_a_failing_command_marks_the_event_failed_and_calls_on_failure(): void
    {
        Artisan::command('drill:inproc-fail', fn () => 1);

        $failed = false;
        $event = (new InProcessSchedule)->command('drill:inproc-fail')->onFailure(function () use (&$failed) {
            $failed = true;
        });
        $event->run($this->app);

        $this->assertNotSame(0, $event->exitCode);
        $this->assertTrue($failed, 'onFailure hooks (SchedulerAlert) still fire');
    }
}
