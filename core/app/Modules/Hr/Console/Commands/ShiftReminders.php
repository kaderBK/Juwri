<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Juwri\Modules\Hr\Models\StaffShift;
use Juwri\Modules\Messaging\Contracts\MessageQueue;

/**
 * SYSTEMS-RUN T-P3: remind staff of shifts starting within the window. Returns
 * the count reminded (push wiring reuses the existing staff push job).
 */
class ShiftReminders extends Command
{
    protected $signature = 'shifts:remind {--hours=2}';

    protected $description = 'Notify staff of upcoming shifts.';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $shifts = StaffShift::where('published', true)
            ->whereNotNull('staff_id')
            ->whereBetween('starts_at', [now(), now()->addHours($hours)])
            ->get();

        foreach ($shifts as $shift) {
            try {
                // The job takes the STORE id; the reminder goes to that staff member's
                // browsers only. The hourly run overlaps the window, so each shift is
                // reminded once.
                if ($shift->staff_id && $shift->store_id
                    && Cache::add('shift_reminder:'.$shift->id, 1, now()->addHours($hours + 1))) {
                    app(MessageQueue::class)->staffPush(
                        (int) $shift->store_id,
                        ___('Shift reminder'),
                        ___('Your shift starts soon.'),
                        '/staff-app',
                        (int) $shift->staff_id
                    );
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info('Shift reminders sent: '.$shifts->count().'.');

        return self::SUCCESS;
    }
}
