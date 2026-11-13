<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pharmacy\Console;

use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Juwri\Core\Support\ScheduleSlots;

/** P1-13: Pharmacy's console schedule (was in app/Console/Kernel), at the same position through Core ScheduleSlots. */
final class PharmacySchedule
{
    public static function register(): void
    {
        // rev/multi-vertical Lane F2: deactivate expired OTC items for pharmacy
        // stores that opted into "hide expired items" (owner-gated, no-op otherwise).
        ScheduleSlots::add('after:owner-pwa:prune-screen-views', 'pharmacy:sweep-expired', fn (Schedule $schedule, Closure $onFail) => $schedule->command('pharmacy:sweep-expired')->daily()->onFailure($onFail('pharmacy:sweep-expired')));
    }
}
