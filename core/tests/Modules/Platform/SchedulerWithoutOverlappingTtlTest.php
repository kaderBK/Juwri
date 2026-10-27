<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * P6-210: every scheduled event that uses ->withoutOverlapping() must pass an
 * explicit TTL (minutes). A bare ->withoutOverlapping() falls back to Laravel's
 * 1440-minute default (24 h) — on a shared host that kills long PHP runs, a
 * killed daily job then self-skips for a whole DAY and scheduled orders stop
 * releasing. The kernel notes the same trap at WI-H7 lines 62-72 and 146-166.
 *
 * This test parses the schedule and fails on any event whose mutex window is
 * still the bare default, so future additions are caught too.
 */
class SchedulerWithoutOverlappingTtlTest extends TestCase
{
    /** Laravel's withoutOverlapping() default when called with no argument (minutes). */
    private const BARE_DEFAULT_MINUTES = 1440;

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

    public function test_no_scheduled_event_uses_the_bare_1440_minute_without_overlapping_default(): void
    {
        $default = [];
        foreach ($this->events() as $event) {
            if ($event->withoutOverlapping !== true) {
                continue;
            }
            if ($event->expiresAt === self::BARE_DEFAULT_MINUTES) {
                $default[] = self::label($event);
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($default)),
            'These scheduled events call ->withoutOverlapping() with no TTL. The bare call self-skips for '
            .self::BARE_DEFAULT_MINUTES.' min (24 h) — on shared hosts a killed run disables the job for a day. '
            .'Pass an explicit minute count (e.g. ->withoutOverlapping(60)).'
        );
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