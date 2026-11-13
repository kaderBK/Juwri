<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use Juwri\Core\Models\PlatformAlert;
use Illuminate\Support\Facades\Log;

/**
 * O4 — the single delivery seam for platform alerts. Persists a row for the
 * superadmin dashboard, logs to the platform_alerts channel (single + slack
 * when LOG_SLACK_WEBHOOK_URL is set), and optionally mails an ops address.
 * De-duped per (day, kind, message) so a recurring condition is one open row.
 */
class AlertDispatcher
{
    public static function send(string $kind, string $message): void
    {
        try {
            Log::channel('platform_alerts')->error('[platform-alert] ' . $message);
        } catch (\Throwable $e) {
            Log::error('[platform-alert] ' . $message);
        }

        try {
            $kind = substr($kind, 0, 60);
            $exists = PlatformAlert::whereDate('day', now()->toDateString())
                ->where('kind', $kind)
                ->where('message', $message)
                ->whereNull('resolved_at')
                ->exists();
            if (! $exists) {
                PlatformAlert::create([
                    'day' => now()->toDateString(),
                    'kind' => $kind,
                    'message' => $message,
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // persistence is best-effort — never break the caller (cron/metrics)
        }
    }
}
