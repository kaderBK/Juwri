<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Platform;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * P6-110: every scheduled command/job/call carries a failure callback (onFailure -> SchedulerAlert::fail), so a
 * silently failing retention, billing or housekeeping task raises an alert. Anything not covered must be on the
 * reviewed exemption list below, with a reason.
 */
class SchedulerAlertCoverageTest extends TestCase
{
    /**
     * Reviewed exemptions: schedule entry label (command name or ->name()) => reason.
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        'scheduler:heartbeat' => 'Cache::put every minute; it IS the liveness signal (scheduler_alive()), a failure shows as a stale heartbeat.',
    ];

    /** @return list<Event> */
    private function events(): array
    {
        $schedule = $this->app->make(Schedule::class);
        $kernel = $this->app->make(\App\Console\Kernel::class);
        $method = new \ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        return $schedule->events();
    }

    public function test_every_scheduled_event_has_a_failure_callback_or_is_exempt(): void
    {
        $after = new \ReflectionProperty(Event::class, 'afterCallbacks');
        $after->setAccessible(true);

        $missing = [];
        foreach ($this->events() as $event) {
            $label = self::label($event);
            if (isset(self::EXEMPT[$label])) {
                continue;
            }
            if ($after->getValue($event) === []) {
                $missing[] = $label;
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Scheduled events without ->onFailure(): add SchedulerAlert::fail or exempt with a reason.');
    }

    public function test_exemptions_are_real_and_justified(): void
    {
        $labels = array_map(fn ($e) => self::label($e), $this->events());
        foreach (self::EXEMPT as $label => $reason) {
            $this->assertNotSame('', trim($reason));
            $this->assertContains($label, $labels, "stale exemption {$label}");
        }
    }

    private static function label(Event $event): string
    {
        if (is_string($event->command) && $event->command !== '') {
            $cmd = preg_replace("/^.*?artisan'?\s+/", '', $event->command);

            return trim(explode(' ', trim($cmd))[0], "'\"");
        }

        return (string) ($event->description ?? $event->getSummaryForDisplay());
    }
}
