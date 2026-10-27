<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Pci\PaymentPageMonitor;

/**
 * PCI DSS 4.0 11.6.1: after reviewing an alerted change (and adding any new script to the inventory), record the
 * payment page as it is now as its known-good baseline.
 */
class AcceptPaymentPageBaselineCommand extends Command
{
    protected $signature = 'pci:accept-baseline {page?* : payment pages to accept (inventory keys)} {--all : every monitored payment page} {--by= : who reviewed the change (kept on the record)}';

    protected $description = 'PCI DSS 11.6.1: accept the current state of payment pages as the new baseline';

    public function handle(PaymentPageMonitor $monitor): int
    {
        $pages = array_values(array_filter((array) $this->argument('page'), 'is_string'));
        if ($pages === [] && ! $this->option('all')) {
            $this->error('Name the payment pages to accept, or pass --all.');

            return self::FAILURE;
        }
        $targets = $monitor->targets($this->option('all') ? null : $pages);
        $unknown = array_diff($pages, array_column($targets, 'page'));
        foreach ($unknown as $page) {
            $this->error("{$page}: not a monitored payment page.");
        }
        $by = trim((string) $this->option('by')) ?: (get_current_user() ?: 'cli');

        $failed = $unknown !== [];
        foreach ($targets as $target) {
            $check = $monitor->accept($target['page'], $target['url'], $by);
            if ($check === null) {
                $this->error("{$target['page']}: could not be fetched; baseline unchanged.");
                $failed = true;

                continue;
            }
            $this->info("{$target['page']}: baseline accepted (".count((array) $check->scripts).' scripts) by '.$by.'.');
            foreach ((array) $check->changes as $warning) {
                $this->warn("  {$warning}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
