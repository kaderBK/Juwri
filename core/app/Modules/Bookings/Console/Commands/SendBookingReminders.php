<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Bookings\Services\ReminderService;

/** P3-10: send the 24-hour booking reminders that are due (every 10 minutes). */
class SendBookingReminders extends Command
{
    protected $signature = 'bookings:remind';

    protected $description = 'Send 24-hour booking reminders (opted-in channels only)';

    public function handle(ReminderService $reminders): int
    {
        $this->info('Reminded '.$reminders->sendDue().' bookings.');

        return self::SUCCESS;
    }
}
