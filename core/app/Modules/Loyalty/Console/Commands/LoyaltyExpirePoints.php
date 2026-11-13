<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Console\Commands;

use App\Models\LoyaltyAccount;
use Illuminate\Console\Command;

/**
 * HPMS E4: expire loyalty points older than each store's window. Per-store window
 * from the `loyalty_points_expiry_days` option (0 = never). Delegates to
 * LoyaltyAccount::expireStale (idempotent, one negative 'adjustment' per run).
 * Scheduled daily in the E Kernel block.
 */
class LoyaltyExpirePoints extends Command
{
    protected $signature = 'loyalty:expire-points';

    protected $description = 'Expire loyalty points older than each store window (E4)';

    public function handle(): int
    {
        $expired = 0;
        $accounts = 0;

        LoyaltyAccount::query()->chunkById(200, function ($chunk) use (&$expired, &$accounts) {
            foreach ($chunk as $account) {
                $days = (int) store_options($account->store_id, 'loyalty_points_expiry_days', '0');
                if ($days <= 0) {
                    continue;
                }
                try {
                    $n = $account->expireStale($days);
                    if ($n > 0) {
                        $expired += $n;
                        $accounts++;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        $this->info("Expired {$expired} point(s) across {$accounts} account(s).");

        return self::SUCCESS;
    }
}
