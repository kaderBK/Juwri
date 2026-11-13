<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Console;

use Illuminate\Console\Scheduling\Schedule;
use Juwri\Core\Support\ScheduleSlots;

/** P1-13: Accounting's console schedule (was in app/Console/Kernel), at the same positions through Core ScheduleSlots. */
final class AccountingSchedule
{
    public static function register(): void
    {
        // SYSTEMS-RUN A-P2: spawn due recurring expenses nightly.
        ScheduleSlots::add('after:queue:prune-failed', 'expenses:generate-recurring', fn (Schedule $schedule) => $schedule->command('expenses:generate-recurring')->dailyAt('02:40')->onFailure(fn () => \App\Support\SchedulerAlert::fail('expenses:generate-recurring')));

        ScheduleSlots::add('after:analytics:forecast', 'accounting:refresh-tokens', fn (Schedule $schedule) => $schedule->command('accounting:refresh-tokens')->hourly()->onFailure(fn () => \App\Support\SchedulerAlert::fail('accounting:refresh-tokens')), order: 10);
    }
}
