<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Juwri\Modules\Integrations\Services\Odoo\OdooSyncQueue;

/**
 * P4-21: send one queued store event to Odoo. Retries are scheduled on the row itself (OdooSyncQueue::BACKOFF,
 * re-dispatched by `odoo:sync-due`), so the job never retries on its own.
 */
class SyncOdooRecordJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public function __construct(public int $recordId) {}

    public function handle(OdooSyncQueue $queue): void
    {
        $queue->run($this->recordId);
    }
}
