<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Juwri\Core\Jobs\GenerateStoreBackupJob;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreBackup;
use Juwri\Core\Models\StoreBackupSchedule;
use Illuminate\Console\Command;

/**
 * Fires every due recurring backup. Runs daily; each schedule advances its own
 * next_run_at by its frequency. Reuses the exact manual pipeline (StoreBackup +
 * GenerateStoreBackupJob), so async build, retention and download are shared.
 * Scheduled archives are never password-protected (no owner present to hold a
 * password).
 */
class RunScheduledBackups extends Command
{
    protected $signature = 'backups:run-scheduled';
    protected $description = 'Create backups for any due recurring schedules';

    public function handle(): int
    {
        $due = StoreBackupSchedule::where('active', true)
            ->where(function ($q) {
                $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
            })
            ->get();

        $fired = 0;
        foreach ($due as $schedule) {
            // The owner must still own the store, else the schedule is stale.
            $ownsStore = Store::where('id', $schedule->store_id)
                ->where('user_id', $schedule->user_id)
                ->exists();
            if (! $ownsStore) {
                $schedule->update(['active' => false]);
                continue;
            }

            // Skip if one is already in flight for this store.
            $inFlight = StoreBackup::where('store_id', $schedule->store_id)
                ->whereIn('status', ['pending', 'processing'])
                ->exists();

            if (! $inFlight) {
                [$from, $to] = $this->window($schedule->orders_window);

                $backup = StoreBackup::create([
                    'store_id' => $schedule->store_id,
                    'user_id'       => $schedule->user_id,
                    'token'         => StoreBackup::newToken(),
                    'status'        => 'pending',
                    'datasets'      => $schedule->datasets,
                    'include_pii'   => (bool) array_intersect((array) $schedule->datasets, \App\Services\Backup\StoreBackupService::PII_DATASETS),
                    'format'        => $schedule->format,
                    'branch_id'     => $schedule->branch_id,
                    'orders_from'   => $from,
                    'orders_to'     => $to,
                    'notify_email'  => $schedule->notify_email,
                    'schedule_id'   => $schedule->id,
                ]);

                StoreBackup::pruneForStore($schedule->store_id, $schedule->user_id);
                GenerateStoreBackupJob::dispatch($backup->id);
                $fired++;
            }

            $schedule->update([
                'last_run_at' => now(),
                'next_run_at' => StoreBackupSchedule::nextRunFor($schedule->frequency),
            ]);
        }

        $this->info("Fired {$fired} scheduled backup(s).");

        return 0;
    }

    /** @return array{0:?string,1:?string} */
    private function window(?string $w): array
    {
        return match ($w) {
            '30' => [now()->subDays(30)->toDateString(), null],
            '90' => [now()->subDays(90)->toDateString(), null],
            default => [null, null],
        };
    }
}
