<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Console\Commands;

use Juwri\Modules\Fiscal\Services\FiscalSlaWatchdog;
use Illuminate\Console\Command;

/**
 * TAX-RUN F0-P2: flag fiscal documents nearing their authority SLA (report-
 * after-print 24h window) so owners + admins are alerted before a breach.
 */
class FiscalSlaWatch extends Command
{
    protected $signature = 'fiscal:sla-watch {--hours=}';

    protected $description = 'Flag fiscal documents queued past the SLA warning threshold';

    public function handle(FiscalSlaWatchdog $watchdog): int
    {
        $hours = $this->option('hours');
        $count = $hours !== null
            ? $watchdog->run((int) $hours)
            : $watchdog->run();

        $this->info("Fiscal SLA watch: {$count} document(s) flagged overdue.");

        return self::SUCCESS;
    }
}
