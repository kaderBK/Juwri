<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Console;

use Illuminate\Console\Scheduling\Schedule;
use Juwri\Core\Support\ScheduleSlots;

/** P1-13: Hotel's console schedule (was in app/Console/Kernel), at the same position through Core ScheduleSlots. */
final class HotelSchedule
{
    public static function register(): void
    {
        ScheduleSlots::add('after:notifications:prune-customer', 'hotel:nightly', function (Schedule $schedule, \Closure $onFail) {
            // Hotel A11: anonymize guest PII on stays checked out >180d (name→'Guest',
            // phone/token null). Idempotent + scheduled-safe.
            $schedule->command('hotel:prune-stays')->dailyAt('03:25')->onFailure($onFail('hotel:prune-stays'));
            // HPMS A10: mark confirmed bookings past arrival + grace a no-show and free
            // the rooms (per-store hotel_noshow_grace_hours, default 18h). Idempotent.
            $schedule->command('hotel:no-show-sweep')->dailyAt('03:35')->onFailure($onFail('hotel:no-show-sweep'));
        });
    }
}
