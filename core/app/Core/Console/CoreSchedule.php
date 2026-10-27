<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console;

use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Juwri\Core\Services\AbuseMonitor;
use Juwri\Core\Support\ScheduleSlots;

/**
 * P1-13: Core's console schedule entries (were in app/Console/Kernel, then the Platform module's until 13e: instance
 * ops a single install needs), at the same positions through Core ScheduleSlots.
 */
final class CoreSchedule
{
    public static function register(): void
    {
        // C2/P6-97: weekly restore drill. ON by default in production, OFF elsewhere; the
        // `platform_backup_restore_check_enabled` setting overrides (PlatformBackupRun::restoreCheckEnabled()).
        ScheduleSlots::add('after:platform:media-backup', 'platform:db-restore-check', fn (Schedule $schedule, Closure $onFail) => $schedule->command('platform:db-restore-check')->weeklyOn(0, '05:00')
            ->withoutOverlapping(120)->onFailure($onFail('platform:db-restore-check')));

        // P5-2: weekly dependency audit + SBOM snapshot (patch cadence).
        ScheduleSlots::add('after:apps:renew', 'platform:supply-chain-audit', fn (Schedule $schedule) => $schedule->command('platform:supply-chain-audit')->weeklyOn(1, '03:00')->onFailure(fn () => \App\Support\SchedulerAlert::fail('platform:supply-chain-audit')));

        // P5-5: abuse scan — cap-breaching themes + phone-home hosts.
        ScheduleSlots::add('after:platform:queue-alerts', 'platform:abuse-scan', fn (Schedule $schedule) => $schedule->call(fn () => AbuseMonitor::scan())
            ->name('platform:abuse-scan')->dailyAt('07:10')->onFailure(fn () => \App\Support\SchedulerAlert::fail('platform:abuse-scan')));

        // P6-156: expired database sessions / cache / cache_locks rows (the session lottery may be 0; the database
        // cache store only deletes an expired key when it is read again).
        ScheduleSlots::add('after:queue:prune-failed', 'juwri:prune-db-stores', fn (Schedule $schedule, Closure $onFail) => $schedule->command('juwri:prune-db-stores')->hourlyAt(17)
            ->withoutOverlapping(30)->onFailure($onFail('juwri:prune-db-stores')));
    }
}
