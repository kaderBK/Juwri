<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Juwri\Core\Models\StoreBackup;
use Illuminate\Console\Command;

/**
 * Retention sweep for owner self-service backups: delete the ZIP file and its
 * row once it is past expires_at. Keeps private disk usage bounded — an owner's
 * export is a transient download, not permanent storage.
 */
class CleanupStoreBackups extends Command
{
    protected $signature = 'backups:cleanup-store';
    protected $description = 'Delete expired self-service store backup archives and their records';

    public function handle(): int
    {
        $expired = StoreBackup::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        $removed = 0;
        foreach ($expired as $backup) {
            if ($backup->path) {
                @unlink(storage_path('app/' . $backup->path));
            }
            $backup->delete();
            $removed++;
        }

        // Records that never finished within a day are dead — mark them failed
        // so the UI stops polling them forever. Clear any transient password on
        // the way (WI-A5 defensive sweep for rows that end non-ready).
        StoreBackup::whereIn('status', ['pending', 'processing'])
            ->where('created_at', '<', now()->subDay())
            ->update(['status' => 'failed', 'password_enc' => null, 'error' => 'Timed out.']);

        // Sweep any orphaned build working directory older than a day (WI-B2).
        // A crash between mkdir and the try/finally, or an OOM-killed worker,
        // can leave a store-backup-* dir holding partial PII dumps.
        $swept = 0;
        foreach (glob(storage_path('app/tmp/store-backup-*')) ?: [] as $dir) {
            if (is_dir($dir) && filemtime($dir) < now()->subDay()->getTimestamp()) {
                $this->rrmdir($dir);
                $swept++;
            }
        }

        $this->info("Removed {$removed} expired store backup(s); swept {$swept} stale working dir(s).");

        return 0;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
