<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Console\Commands;

use Juwri\Modules\Hr\Models\StaffDocument;
use Illuminate\Console\Command;

/**
 * SYSTEMS-RUN H-P1: daily staff-document expiry scan. Returns the count of
 * documents expiring within the window (owner notification + dashboard badge
 * consume the same query via StaffDocument::isExpiringSoon).
 */
class StaffDocumentsExpiryAlert extends Command
{
    protected $signature = 'staff:documents-expiry-alert {--days=30}';

    protected $description = 'Report staff documents expiring soon.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $count = StaffDocument::whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays($days)->toDateString())
            ->whereDate('expires_at', '>=', now()->subDays(365)->toDateString())
            ->count();

        $this->info("Staff documents expiring within {$days} days: {$count}.");
        return self::SUCCESS;
    }
}
