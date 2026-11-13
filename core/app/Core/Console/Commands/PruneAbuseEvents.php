<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Juwri\Core\Models\AbuseEvent;
use Illuminate\Console\Command;

/**
 * Abuse Guard P6: prune old abuse_events. In warn mode high-volume guards write a
 * row per overage, so the table would grow unbounded. Retention is admin-editable
 * (abuse_events_retention_days, default 90). 0 = keep forever.
 */
class PruneAbuseEvents extends Command
{
    protected $signature = 'abuse:events-prune {--days=}';
    protected $description = 'Delete abuse_events older than the retention window';

    public function handle(): int
    {
        $optDays = $this->option('days');
        $days = ($optDays !== null && $optDays !== '')
            ? (int) $optDays
            : (int) config('settings.abuse_events_retention_days', 90);
        if ($days <= 0) {
            $this->info('Retention disabled (0 days) — nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = AbuseEvent::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} abuse_events older than {$days} days.");

        return self::SUCCESS;
    }
}
