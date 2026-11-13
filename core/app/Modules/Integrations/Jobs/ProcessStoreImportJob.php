<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Jobs;

use App\Services\Backup\StoreImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Modules\Integrations\Models\StoreImport;

/**
 * Applies a confirmed store import off the web request (heavy queue). One try —
 * the apply runs in a DB transaction, so a failure rolls back and the row is
 * marked failed rather than leaving a half-imported store.
 */
class ProcessStoreImportJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $importId)
    {
        $this->onQueue('heavy');
    }

    public function handle(StoreImportService $service): void
    {
        $import = StoreImport::find($this->importId);
        if (! $import || $import->status !== 'processing') {
            return;
        }

        $target = Store::where('id', $import->store_id)
            ->where('user_id', $import->user_id)
            ->first();
        if (! $target) {
            $import->update(['status' => 'failed', 'error' => 'Target store not found or not owned.']);

            return;
        }

        // P6-316: one runner per import even if the job is delivered twice.
        $lock = Cache::lock('store-import:'.$import->id, $this->timeout + 60);
        if (! $lock->get()) {
            return;
        }
        $import->refresh();
        if ($import->status !== 'processing') {
            $lock->release();

            return;
        }

        try {
            $summary = $service->apply($import, $target);
            $import->update(['status' => 'done', 'summary' => $summary, 'error' => null]);
        } catch (\Throwable $e) {
            Log::error('Store import failed', ['import' => $this->importId, 'error' => $e->getMessage()]);
            $import->update(['status' => 'failed', 'error' => 'The import could not be completed. No partial data was written.']);
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        StoreImport::where('id', $this->importId)->where('status', '!=', 'done')
            ->update(['status' => 'failed', 'error' => 'The import could not be completed.']);
    }
}
