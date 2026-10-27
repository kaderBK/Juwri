<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use App\Services\Account\AccountDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\AccountDeletion;
use Juwri\Core\Services\AlertDispatcher;

/** Owner account deletion: permanently purge accounts whose grace period ended. */
class PurgeDeletedAccountsCommand extends Command
{
    protected $signature = 'accounts:purge-deleted {--limit=20 : Accounts per run}';

    protected $description = 'Permanently delete owner accounts whose deletion grace period has ended';

    /** After this many failed purges a row is marked failed and stops competing for the daily slots. */
    public const MAX_ATTEMPTS = 5;

    public function handle(AccountDeletionService $service): int
    {
        // P6-244: closed accounts whose gateway cancel failed are retried first (the gateway is still billing them).
        $retry = $service->retryGatewayCancels();
        if ($retry['retried'] > 0) {
            $this->warn("Gateway cancel retried for {$retry['retried']} closed account(s), {$retry['cancelled']} cancelled.");
        }

        $limit = max(1, (int) $this->option('limit'));
        $done = 0;
        $failures = 0;

        // P6-247: rows over their attempt budget are marked failed (one alert each) and never block the later rows.
        AccountDeletion::where('status', AccountDeletion::PENDING)
            ->where('purge_after', '<=', now())
            ->orderBy('purge_after')
            ->get()
            ->each(function (AccountDeletion $deletion) use ($service, $limit, &$done, &$failures): void {
                if ($done >= $limit) {
                    return;
                }
                $report = is_array($deletion->purge_report) ? $deletion->purge_report : [];
                $attempts = (int) ($report['failed_attempts'] ?? 0);
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $deletion->forceFill(['status' => AccountDeletion::FAILED])->save();

                    return;
                }
                $done++;

                try {
                    $result = $service->purge($deletion);
                    if (! empty($result['skipped'])) {
                        $this->warn("Skipped account deletion #{$deletion->id}: ".($result['reason'] ?? 'not due'));

                        return;
                    }
                    $this->info("Purged account deletion #{$deletion->id} (user {$deletion->user_id}).");
                } catch (\Throwable $e) {
                    $failures++;
                    $attempts++;
                    $report['failed_attempts'] = $attempts;
                    $report['last_error'] = mb_substr($e->getMessage(), 0, 500);
                    $final = $attempts >= self::MAX_ATTEMPTS;
                    try {
                        $deletion->forceFill([
                            'purge_report' => $report,
                            'status' => $final ? AccountDeletion::FAILED : AccountDeletion::PENDING,
                        ])->save();
                    } catch (\Throwable $saveError) {
                        Log::error('[account-deletion] could not record the failed attempt', ['deletion' => $deletion->id, 'error' => $saveError->getMessage()]);
                    }
                    Log::error('[account-deletion] purge failed', ['deletion' => $deletion->id, 'attempt' => $attempts, 'error' => $e->getMessage()]);
                    $this->error("Failed #{$deletion->id} (attempt {$attempts}/".self::MAX_ATTEMPTS."): {$e->getMessage()}");
                    AlertDispatcher::send('account-deletion', "account deletion #{$deletion->id} purge failed (attempt {$attempts}/".self::MAX_ATTEMPTS.($final ? ', giving up until reset' : '').'): '.mb_substr($e->getMessage(), 0, 300));
                }
            });

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
