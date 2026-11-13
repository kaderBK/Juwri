<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Payments\Services\PaymentsWatch;

/** P6-93: payments health watch — see {@see PaymentsWatch}. Scheduled every 5 minutes. */
class PaymentsWatchCommand extends Command
{
    protected $signature = 'payments:watch';

    protected $description = 'Alert on stuck payments, webhook rejections, refunds needing reconcile, ledger drift, payouts and outbox backlog';

    public function handle(PaymentsWatch $watch): int
    {
        $messages = $watch->run();
        foreach ($messages as $message) {
            $this->warn($message);
        }
        if ($messages === []) {
            $this->info('Payments healthy.');
        }

        return self::SUCCESS;
    }
}
