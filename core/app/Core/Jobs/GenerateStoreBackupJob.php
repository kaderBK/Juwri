<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Jobs;

use Juwri\Core\Mail\BackupReadyMail;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreBackup;
use App\Services\Backup\StoreBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Builds a per-restaurant backup ZIP off the web request. On a `sync` queue it
 * runs inline (fine for a small store); on a real queue it runs on `heavy`.
 * One try — a half-written archive is never marked ready.
 */
class GenerateStoreBackupJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public int $backupId)
    {
        $this->onQueue('heavy');
    }

    public function handle(StoreBackupService $service): void
    {
        $backup = StoreBackup::find($this->backupId);
        if (! $backup || $backup->status === 'ready') {
            return;
        }

        // O12b: carry the same request-id-style context on this heavy job's log
        // lines so a backup failure is greppable back to its store.
        Log::withContext([
            'request_id' => 'job:backup:'.$this->backupId,
            'store_id'   => $backup->store_id,
        ]);

        $backup->update(['status' => 'processing']);

        try {
            $service->build($backup);
            $this->notify($backup->fresh());
        } catch (\Throwable $e) {
            Log::error('Store backup failed', ['backup' => $this->backupId, 'error' => $e->getMessage()]);
            // WI-A5: never leave the transient password on a non-ready row.
            $backup->update(['status' => 'failed', 'password_enc' => null, 'error' => 'The backup could not be generated. Please try again.']);
        }
    }

    /** Email the owner if they asked to be notified. A mail failure is not fatal. */
    private function notify(?StoreBackup $backup): void
    {
        if (! $backup || ! $backup->notify_email || ! $backup->isReady()) {
            return;
        }
        try {
            $storeName = Store::where('id', $backup->store_id)->value('title');
            Mail::to($backup->notify_email)->send(new BackupReadyMail($backup, $storeName));
        } catch (\Throwable $e) {
            Log::warning('Backup-ready email failed', ['backup' => $backup->id, 'error' => $e->getMessage()]);
        }
    }

    /** If the queue itself drops the job, leave a truthful failed state. */
    public function failed(\Throwable $e): void
    {
        StoreBackup::where('id', $this->backupId)->where('status', '!=', 'ready')
            ->update(['status' => 'failed', 'password_enc' => null, 'error' => 'The backup could not be generated. Please try again.']);
    }
}
