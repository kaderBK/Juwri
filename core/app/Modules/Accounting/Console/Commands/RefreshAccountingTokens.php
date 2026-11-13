<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Console\Commands;

use Juwri\Modules\Accounting\Services\Accounting\AccountingTokenService;
use Illuminate\Console\Command;

/**
 * GAPS-RUN-2 B3-P1: keep dormant accounting connections alive by rotating their
 * OAuth tokens before they expire (Xero refresh tokens have a 60-day idle window).
 */
class RefreshAccountingTokens extends Command
{
    protected $signature = 'accounting:refresh-tokens';

    protected $description = 'Refresh dormant accounting OAuth tokens (rotation-safe)';

    public function handle(AccountingTokenService $service): int
    {
        $n = $service->refreshDormant();
        $this->info("Accounting tokens refreshed: {$n}.");
        return self::SUCCESS;
    }
}
