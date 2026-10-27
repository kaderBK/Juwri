<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner dashboard PWA (PWA-SPEC §3.6): keep 90 days of screen-view stats. */
class PruneOwnerScreenViewsCommand extends Command
{
    protected $signature = 'owner-pwa:prune-screen-views {--days=90}';

    protected $description = 'Delete owner dashboard screen-view stats older than N days';

    public function handle(): int
    {
        if (! Schema::hasTable('owner_screen_views')) {
            return self::SUCCESS;
        }

        $days = max(7, (int) $this->option('days'));
        $deleted = DB::table('owner_screen_views')->where('day', '<', now()->subDays($days)->toDateString())->delete();
        $this->info("Pruned {$deleted} rows older than {$days} days.");

        return self::SUCCESS;
    }
}
