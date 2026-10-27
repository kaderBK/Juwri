<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Console\Commands;

use App\Services\Marketing\WinBackService;
use Illuminate\Console\Command;

/**
 * GAPS-RUN-2 B2-P2: daily lapsed-customer win-back offers.
 */
class WinBackOffers extends Command
{
    protected $signature = 'marketing:win-back';

    protected $description = 'Send win-back coupons to lapsed customers';

    public function handle(WinBackService $service): int
    {
        $res = $service->run();
        $this->info("Win-back: {$res['offers']} offer(s) across {$res['stores']} restaurant(s).");

        return self::SUCCESS;
    }
}
