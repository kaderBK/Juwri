<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * Hourly housekeeping: purge abandoned checkouts, then the `cron.hourly` hooks (Billing: expire lapsed plan
 * upgrades — the user drops back to the free group; P1-13 moved that step into the Billing module).
 */
class HourlyHousekeeping extends Command
{
    /** A checkout that never got a status within this many hours was abandoned. */
    private const ABANDONED_AFTER_HOURS = 3;

    /** A Paddle / Lemon Squeezy checkout without a settlement is kept this many days (it may still be paid). */
    private const MOR_KEEP_DAYS = 7;

    protected $signature = 'cron:hourly';

    protected $description = 'Hourly housekeeping: abandoned checkouts, lapsed plan upgrades';

    public function handle(): int
    {
        /* BL-038: only never-settled rows (no status) are purged — a settled merchant-of-record checkout is marked
           (recurring_setup / checkout_settled) by its settlement and kept for its renewals. A Paddle / Lemon Squeezy
           checkout that was sent to its gateway may still be paid there (or its webhook retried) long after 3 hours:
           it is kept MOR_KEEP_DAYS before it goes. */
        $stale = Transaction::query()->whereNull('status')->where('created_at', '<=', now()->subHours(self::ABANDONED_AFTER_HOURS));
        $keep = $stale->clone()->whereNotNull('details')->where('created_at', '>', now()->subDays(self::MOR_KEEP_DAYS))->get()
            ->filter(fn (Transaction $t): bool => in_array(data_get($t->details, 'checkout_gateway'), Transaction::MOR_GATEWAYS, true))
            ->modelKeys();
        $stale->clone()->whereKeyNot($keep)->delete();

        // BL-039: PayPal subscription checkouts awaiting their first sale are marked `recurring_pending`
        // so the 3-hour purge spares them; drop them once the keep window expires if they never settled.
        Transaction::query()
            ->where('status', Transaction::STATUS_RECURRING_PENDING)
            ->where('created_at', '<=', now()->subDays(self::MOR_KEEP_DAYS))
            ->delete();

        Hooks::call('cron.hourly');

        return self::SUCCESS;
    }
}
