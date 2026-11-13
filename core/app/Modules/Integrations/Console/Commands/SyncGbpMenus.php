<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Console\Commands;

use Juwri\Modules\Integrations\Jobs\SyncGbpMenuJob;
use Juwri\Core\Models\Credential;
use Illuminate\Console\Command;

/**
 * google-partner Lane A / A5 — nightly GBP menu sync fan-out.
 *
 * Dark no-op: iterates only gbp Credentials that are active, have a linked location,
 * and have gbp_menu_sync_enabled on. On an unconfigured platform this loops over zero
 * rows and dispatches nothing (zero jobs, zero HTTP).
 */
class SyncGbpMenus extends Command
{
    protected $signature = 'gbp:sync-menus';

    protected $description = 'Queue GBP FoodMenus sync for every store with menu-sync enabled (dark no-op when unconfigured).';

    public function handle(): int
    {
        $dispatched = 0;

        Credential::where('feature', 'gbp')->where('active', true)
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$dispatched) {
                foreach ($rows as $row) {
                    $secrets = $row->secrets ?? [];
                    if (empty($secrets['location_id']) || empty($secrets['gbp_menu_sync_enabled'])) {
                        continue;
                    }
                    SyncGbpMenuJob::dispatch((int) $row->store_id);
                    $dispatched++;
                }
            });

        $this->info("gbp:sync-menus dispatched {$dispatched} store(s).");

        return self::SUCCESS;
    }
}
