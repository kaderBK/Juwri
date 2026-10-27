<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Carbon\Carbon;
use FilesystemIterator;
use Illuminate\Console\Command;

class CleanupUserBackups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron:cleanup-user-backups {--days=14 : Remove backup files older than this many days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup stale user backup archives, extraction folders, and reports';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = Carbon::now()->subDays($days)->timestamp;
        $baseDirectory = storage_path('app/user-backups');

        if (!is_dir($baseDirectory)) {
            $this->info('No user backup directory found.');
            return 0;
        }

        $deletedFiles = 0;
        $deletedDirectories = 0;

        $this->cleanupDirectory($baseDirectory, $cutoff, $deletedFiles, $deletedDirectories, true);

        $this->info("Cleanup complete. Deleted {$deletedFiles} files and {$deletedDirectories} directories older than {$days} days.");

        return 0;
    }

    protected function cleanupDirectory(string $directory, int $cutoff, int &$deletedFiles, int &$deletedDirectories, bool $isRoot = false): void
    {
        $items = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            if ($item->isDir()) {
                $this->cleanupDirectory($item->getPathname(), $cutoff, $deletedFiles, $deletedDirectories);
                continue;
            }

            if ($item->getMTime() <= $cutoff && @unlink($item->getPathname())) {
                $deletedFiles++;
            }
        }

        if (!$isRoot && $this->isDirectoryEmpty($directory) && @rmdir($directory)) {
            $deletedDirectories++;
        }
    }

    protected function isDirectoryEmpty(string $directory): bool
    {
        $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $unused) {
            return false;
        }

        return true;
    }
}
