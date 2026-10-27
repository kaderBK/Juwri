<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use App\Services\Account\AccountDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision 2026-10-07: a deleted account's security logs (sign-ins, security events) are kept 12 months after the
 * purge, then deleted here (daily).
 *
 * Privacy notice §8 ("Security and access logs: 12 months"): sign-in records (login_audits) of live accounts are also
 * deleted once older than AccountDeletionService::SECURITY_LOG_DAYS. A device not seen for that long counts as new again.
 */
class PruneSecurityLogsCommand extends Command
{
    protected $signature = 'accounts:prune-security-logs {--limit=50 : Accounts per run}';

    protected $description = 'Delete security logs older than 12 months (purged accounts\' kept logs and live sign-in records)';

    public function handle(AccountDeletionService $service): int
    {
        foreach ($service->pruneSecurityLogs((int) $this->option('limit')) as $deletion => $rows) {
            $this->info("Account deletion #{$deletion}: {$rows} security log rows deleted.");
        }

        if (Schema::hasTable('login_audits')) {
            $cutoff = now()->subDays(AccountDeletionService::SECURITY_LOG_DAYS);
            $total = 0;
            do {
                $n = DB::table('login_audits')->where('created_at', '<', $cutoff)->limit(1000)->delete();
                $total += $n;
            } while ($n > 0);
            $this->info("Sign-in records older than 12 months: {$total} deleted.");
        }

        return self::SUCCESS;
    }
}
