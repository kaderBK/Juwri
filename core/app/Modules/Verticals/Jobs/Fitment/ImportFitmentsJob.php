<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Jobs\Fitment;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Juwri\Modules\Verticals\Models\FitmentImport;
use Juwri\Modules\Verticals\Services\Fitment\FitmentImporter;

/**
 * P3-08b: the store fitment CSV of more than {@see FitmentImporter::INLINE_ROWS} rows, imported by the queue. Runs for
 * the dispatcher's tenant (TenantJobs); the import row is read through the tenant scope, so another account's id is
 * "not found" and the job ends. Safe to retry: a finished or failed import is left alone, and fitments already added
 * count as present.
 */
class ImportFitmentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $importId, public int $storeId)
    {
        // P6-64b: long job (900s) runs on the heavy/long-retry lane
        $this->onQueue('heavy');
    }

    public function handle(FitmentImporter $importer): void
    {
        $import = FitmentImport::query()->where('store_id', $this->storeId)->find($this->importId);
        if ($import === null || $import->status !== 'queued') {
            return;
        }
        $importer->run($import);
    }
}
