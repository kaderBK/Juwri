<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Verticals\Models\SkinProfile;

/**
 * P6-71: skin_profiles rows are created by anonymous visitors (one per session token), so they grow without
 * bound. Delete anonymous (no customer) rows not touched for 30 days. Rows tied to a customer are kept.
 */
class PruneSkinProfiles extends Command
{
    protected $signature = 'verticals:prune-skin-profiles {--days=30}';

    protected $description = 'Delete anonymous skin_profiles rows older than 30 days';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $n = SkinProfile::withoutGlobalScopes()
            ->whereNull('customer_id')
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();
        $this->info("Pruned {$n} skin profile row(s).");

        return self::SUCCESS;
    }
}
