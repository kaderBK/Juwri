<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** P6-118 — password_reset_tokens is pruned daily for every configured password broker. */
class PasswordResetPruneScheduleTest extends TestCase
{
    public function test_clear_resets_is_scheduled_daily_for_every_broker(): void
    {
        $brokers = array_keys((array) config('auth.passwords'));
        $this->assertNotEmpty($brokers);

        $events = collect(app(Schedule::class)->events());

        foreach ($brokers as $broker) {
            $event = $events->first(fn (Event $e) => str_contains((string) $e->command, 'auth:clear-resets '.$broker));
            $this->assertNotNull($event, "auth:clear-resets not scheduled for broker {$broker}");
            $this->assertSame('0 0 * * *', $event->expression);
        }
    }
}
