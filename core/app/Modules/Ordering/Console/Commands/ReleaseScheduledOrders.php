<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Console\Commands;

use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Illuminate\Console\Command;

/**
 * T1: per-minute tick that releases scheduled (pre-)orders to the live kitchen
 * queues once their fire time arrives. Idempotent — an already-released order is
 * skipped, so running it twice (or after downtime) never double-fires.
 */
class ReleaseScheduledOrders extends Command
{
    protected $signature = 'orders:release-scheduled';

    protected $description = 'Release scheduled orders whose kitchen fire time has arrived';

    public function handle(SchedulingService $scheduling): int
    {
        $released = $scheduling->releaseDue();
        if ($released > 0) {
            $this->info("Released {$released} scheduled order(s).");
        }
        return self::SUCCESS;
    }
}
