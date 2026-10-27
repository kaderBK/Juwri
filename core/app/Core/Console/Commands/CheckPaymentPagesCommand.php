<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Models\PaymentPageCheck;
use Juwri\Core\Pci\PaymentPageMonitor;

/**
 * PCI DSS 4.0 11.6.1: fetch every payment page as a customer would and compare its scripts + security headers with
 * the accepted baseline (scheduled weekly, payment_scripts.monitor.cron). Changes and errors are alerted through the
 * platform AlertDispatcher and make the command fail (so the scheduler's failure hook fires too).
 */
class CheckPaymentPagesCommand extends Command
{
    protected $signature = 'pci:check-payment-pages {--page=* : only these payment pages (inventory keys)}';

    protected $description = 'PCI DSS 11.6.1: detect changes to the scripts and security headers of the payment pages';

    public function handle(PaymentPageMonitor $monitor): int
    {
        $only = array_values(array_filter((array) $this->option('page'), 'is_string'));
        $checks = $monitor->run($only === [] ? null : $only);
        if ($checks === []) {
            $this->warn('No payment page to check (no probe could render and no target is configured).');

            return self::SUCCESS;
        }

        $this->table(['page', 'status', 'scripts', 'changes / error'], array_map(fn (PaymentPageCheck $c) => [
            $c->page, $c->status, count((array) $c->scripts),
            $c->error ?? mb_substr(implode('; ', (array) $c->changes), 0, 160),
        ], $checks));

        $bad = array_filter($checks, fn (PaymentPageCheck $c) => in_array($c->status, [PaymentPageCheck::STATUS_CHANGED, PaymentPageCheck::STATUS_ERROR], true));

        return $bad === [] ? self::SUCCESS : self::FAILURE;
    }
}
