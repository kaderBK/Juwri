<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Platform;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * P6-111: RETENTION-MATRIX.md lists the command behind every retention promise. Each listed command
 * must exist and be scheduled, so the matrix cannot drift from the code.
 */
class RetentionMatrixTest extends TestCase
{
    public function test_every_matrix_command_is_scheduled(): void
    {
        $file = base_path('../rev').'/juwri-run/RETENTION-MATRIX.md';
        if (! is_file($file)) {
            $this->markTestSkipped('RETENTION-MATRIX.md is not part of this checkout.');
        }

        $commands = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $cells = array_map('trim', explode('|', $line));
            if (count($cells) < 9 || ! preg_match('/^`([a-z0-9:_-]+)`$/i', $cells[3])) {
                continue;
            }
            $commands[] = trim($cells[3], '`');
        }
        $this->assertGreaterThan(15, count($commands), 'matrix parse found too few commands');

        $schedule = $this->app->make(Schedule::class);
        $kernel = $this->app->make(\App\Console\Kernel::class);
        $m = new \ReflectionMethod($kernel, 'schedule');
        $m->setAccessible(true);
        $m->invoke($kernel, $schedule);

        $after = new \ReflectionProperty(Event::class, 'afterCallbacks');
        $after->setAccessible(true);

        $scheduled = [];
        foreach ($schedule->events() as $event) {
            if (is_string($event->command) && preg_match("/artisan'?\s+'?([a-z0-9:_-]+)/i", $event->command, $mm)) {
                $scheduled[$mm[1]] = $event;
            }
        }

        foreach (array_unique($commands) as $cmd) {
            $this->assertArrayHasKey($cmd, $scheduled, "matrix command {$cmd} is not scheduled");
            $this->assertNotEmpty($after->getValue($scheduled[$cmd]), "matrix command {$cmd} has no failure alert");
        }
    }
}
