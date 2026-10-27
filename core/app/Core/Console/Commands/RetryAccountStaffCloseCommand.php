<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use App\Services\Account\AccountDeletionService;
use Illuminate\Console\Command;

/**
 * BL-036 round 7: an account closure whose staff step failed (lock-wait timeout, deadlock) left the owner's staff
 * signed in. Retried here until the step succeeds, a few minutes after the request.
 */
class RetryAccountStaffCloseCommand extends Command
{
    protected $signature = 'accounts:retry-staff-close';

    protected $description = 'Switch off the staff of closed accounts whose closure staff step failed';

    public function handle(AccountDeletionService $service): int
    {
        $out = $service->retryStaffClosures();
        if ($out['retried'] > 0) {
            $this->warn("Staff step retried for {$out['retried']} closed account(s), {$out['closed']} done.");
        }

        return self::SUCCESS;
    }
}
