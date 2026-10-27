<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Console;

use Illuminate\Console\Scheduling\Schedule;
use Juwri\Core\Support\ScheduleSlots;

/** P6-71: Verticals' console schedule, through Core ScheduleSlots (P6-54 pattern: onFailure alert). */
final class VerticalsSchedule
{
    public static function register(): void
    {
        ScheduleSlots::add('after:notifications:prune-customer', 'verticals:nightly', function (Schedule $schedule, \Closure $onFail) {
            $schedule->command('verticals:prune-skin-profiles')->dailyAt('03:45')->onFailure($onFail('verticals:prune-skin-profiles'));
        });
    }
}
