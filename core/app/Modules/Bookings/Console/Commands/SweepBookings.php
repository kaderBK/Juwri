<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Bookings\Services\HoldService;

/** P3-10: free expired holds and cancel bookings whose deposit payment window ended (API-10/11). Every minute. */
class SweepBookings extends Command
{
    protected $signature = 'bookings:sweep';

    protected $description = 'Release expired booking holds and unpaid bookings past their payment window';

    public function handle(HoldService $holds): int
    {
        $cells = $holds->purgeExpired();
        $this->info("Freed {$cells} expired cells.");

        return self::SUCCESS;
    }
}
