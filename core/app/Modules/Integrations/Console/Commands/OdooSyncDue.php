<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Integrations\Jobs\SyncOdooRecordJob;
use Juwri\Modules\Integrations\Services\Odoo\OdooSyncQueue;

/** P4-21: dispatch the Odoo sync rows whose next attempt is due (scheduled every 5 minutes). */
class OdooSyncDue extends Command
{
    protected $signature = 'odoo:sync-due {--limit=200}';

    protected $description = 'Send due Odoo sync rows (new and retrying) to Odoo';

    public function handle(): int
    {
        $ids = OdooSyncQueue::due((int) $this->option('limit'));
        foreach ($ids as $id) {
            SyncOdooRecordJob::dispatch($id);
        }
        $this->info(count($ids).' Odoo sync row(s) dispatched.');

        return self::SUCCESS;
    }
}
