<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Listeners\Queue;

use App\Services\PlatformMetrics;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Services\AlertDispatcher;

/**
 * P6-211 — surface a failed queued job to the operator.
 *
 * Before this, the only signal was {@see PlatformMetrics}'s
 * daily 25-row threshold (O6). Two failure shapes followed:
 *   1. the 1st–24th payment failure of the day was invisible;
 *   2. every job that pushed the count over 25 spammed an alert on every daily
 *      sweep until the row was pruned (weekly, 168 h).
 *
 * Routing:
 *   - payment / order-mail / backup classes — alert on every failure. Each one
 *     is a per-customer problem a customer is waiting on, so deduping would
 *     silently drop a paying diner's checkout.
 *   - anything else — dedupe with a 1 h cache key per job class so a runaway
 *     loop still alerts, but a single transient failure does not flood the
 *     dashboard.
 *
 * Pure read of the event payload. Never throws — the queue worker must not
 * crash because the listener swallowed a failure.
 */
class AlertOnJobFailed
{
    /** Per-class dedupe window for non-critical failures. */
    private const DEDUPE_TTL = 3600; // seconds — 1 hour

    public function handle(JobFailed $event): void
    {
        try {
            $class = $this->classOf($event);
            if ($class === '') {
                return;
            }

            $exception = mb_substr($event->exception->getMessage(), 0, 200);
            $critical = $this->isCritical($class);

            if ($critical || Cache::add($this->cacheKey($class), 1, self::DEDUPE_TTL)) {
                AlertDispatcher::send('queue.failed', $this->message($class, $exception, $critical));
            }
        } catch (\Throwable $e) {
            // never break the worker
            report($e);
        }
    }

    /** FQN of the queued handler — `*Order*Mail*` resolves to the mailable. */
    private function classOf(JobFailed $event): string
    {
        try {
            $payload = $event->job->payload();
        } catch (\Throwable) {
            return '';
        }

        $name = $payload['displayName'] ?? null;
        if (! is_string($name) || $name === '') {
            try {
                $name = $event->job->resolveName();
            } catch (\Throwable) {
                return '';
            }
        }

        return (string) $name;
    }

    /**
     * payment / order-mail / backup failures are per-customer problems and
     * must NOT be deduped.
     *
     *  - payment — `\Payments\` namespace (covers `Modules/Payments/*`, including
     *    `RelayOutboxJob` and `PaymentHealthAlertMail`);
     *  - order-mail — `*Order*Mail*` matches `App\Mails\StoreOrderMail`,
     *    `OrderCapNoticeMail`, `OrderReminderMail`, …
     *  - backup — anything containing `Backup` (`GenerateStoreBackupJob`,
     *    `BuildAccountExportJob`, `BackupReadyMail`).
     */
    private function isCritical(string $class): bool
    {
        return str_contains($class, '\\Payments\\')
            || (str_contains($class, 'Order') && str_contains($class, 'Mail'))
            || str_contains($class, 'Backup');
    }

    private function cacheKey(string $class): string
    {
        // The cache store does not accept colons in some drivers; hash so the key
        // is alphanumeric and stable per FQN.
        return 'queue:job-failed-alert:'.hash('xxh128', $class);
    }

    private function message(string $class, string $exception, bool $critical): string
    {
        if ($critical) {
            return ___('Critical queued job :class failed: :error', ['class' => $class, 'error' => $exception]);
        }

        return ___('Queued job :class failed: :error', ['class' => $class, 'error' => $exception]);
    }
}
