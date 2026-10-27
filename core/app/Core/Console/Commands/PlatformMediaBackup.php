<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Juwri\Core\Console\Commands\Concerns\ChecksOffboxDisk;
use Juwri\Core\Models\PlatformBackupRun;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Services\Ops\BackupCrypto;

/**
 * M7: mirror the platform's media tree off-box.
 *
 * The DB dump (platform:db-backup) covers rows; every image those rows point at
 * lives on the repo-root storage/ tree and was in no artifact at all. Restoring
 * the dump alone yields a platform whose media all 404s.
 *
 * Incremental: an object whose size already matches is skipped, so only the
 * first run is expensive. OFF by default — it costs S3 storage and egress, so
 * the operator opts in (Settings -> General -> "Mirror media off-box nightly").
 *
 * Mirrors UP only. There is no platform:media-restore — restoring media is
 * `aws s3 sync` in reverse and belongs in the operator runbook; with P6-96b encryption (BACKUP_ENCRYPTION_KEY set)
 * each mirrored object is a JWBK file, decrypted after the sync with `backup:decrypt <dir> <out-dir>`.
 *
 * P1-23 retention: a file deleted locally stays in the mirror for `backup.keep_days` (the 30 days the backups are
 * kept — a backup must survive a deletion), then is removed from it, so deleted data leaves the backups within the
 * Terms' 90 days (7.6). The first run that sees a mirrored file missing locally records the date in
 * `<prefix>.deleted.json` on the off-box disk. Each mirrored key is checked against the local tree itself, so a run
 * stopped at the file ceiling never mistakes an unwalked file for a deleted one.
 */
class PlatformMediaBackup extends Command
{
    use ChecksOffboxDisk;

    protected $signature = 'platform:media-backup {--dry-run} {--force}';

    protected $description = 'Mirror the platform media tree to the off-box (S3) disk, incrementally';

    public function handle(): int
    {
        if (! $this->option('force') && ! (bool) config('settings.platform_media_backup_enabled')) {
            $this->warn('platform:media-backup skipped (disabled — enable it in Settings → General)');

            return self::SUCCESS;
        }

        if (! $this->s3Configured()) {
            $this->error('platform:media-backup: no off-box target configured (AWS_ACCESS_KEY_ID / AWS_BUCKET)');
            $this->recordRun(false, 0, 0, 'no off-box target configured');
            AlertDispatcher::send('backup', 'platform media mirror cannot run: no off-box target configured');

            return self::FAILURE;
        }

        // J45 (P6-204): same rule as the DB dump — a live site never mirrors media off-box without the key.
        if (BackupCrypto::missingInProduction()) {
            $this->error('platform:media-backup failed: '.BackupCrypto::MISSING_KEY_IN_PRODUCTION);
            $this->recordRun(false, 0, 0, BackupCrypto::MISSING_KEY_IN_PRODUCTION);
            AlertDispatcher::send('backup', 'platform media mirror '.BackupCrypto::MISSING_KEY_IN_PRODUCTION);

            return self::FAILURE;
        }

        $root = rtrim((string) (config('backup.media_root') ?: public_path('storage')), '/');
        if (! is_dir($root)) {
            $this->error("platform:media-backup: media root [{$root}] does not exist");
            $this->recordRun(false, 0, 0, 'media root missing');

            return self::FAILURE;
        }

        $prefix = trim((string) config('backup.media_prefix', 'backups/platform/media'), '/');
        $exclude = (array) config('backup.media_exclude', []);
        $maxFiles = (int) config('backup.media_max_files_per_run', 20000);

        $seen = 0;
        $uploaded = 0;
        $skipped = 0;
        $failed = 0;
        $bytes = 0;
        $started = microtime(true);
        $disk = $this->offboxDisk(); // P6-166: a failed call carries the store's reason
        try {
            $key32 = BackupCrypto::currentKey(); // P6-96b: same key as the DB dumps; null = plain (S3 SSE only)
        } catch (\Throwable $e) {
            $this->error('platform:media-backup: '.$e->getMessage());
            $this->recordRun(false, 0, 0, 'malformed BACKUP_ENCRYPTION_KEY');
            AlertDispatcher::send('backup', 'platform media mirror cannot run: malformed BACKUP_ENCRYPTION_KEY');

            return self::FAILURE;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
            foreach ($exclude as $skip) {
                if ($rel === $skip || str_starts_with($rel, rtrim($skip, '/').'/')) {
                    continue 2;
                }
            }

            $seen++;
            if ($seen > $maxFiles) {
                $this->warn("stopped at the {$maxFiles}-file ceiling for this run");
                break;
            }

            $key = $prefix.'/'.$rel;
            $size = (int) $file->getSize();

            try {
                // Incremental: same size == already mirrored. Cheap HEAD, no GET.
                // P6-96b: encrypted mirror objects are compared by their exact sealed size; a plain object left by
                // an earlier run has a different size, so it is re-uploaded encrypted.
                $want = $key32 !== null ? BackupCrypto::encryptedSize($size) : $size;
                if ($disk->exists($key) && (int) $disk->size($key) === $want) {
                    $skipped++;

                    continue;
                }
                if ($this->option('dry-run')) {
                    $uploaded++;
                    $bytes += $size;

                    continue;
                }
                $this->upload($disk, $key, $file->getPathname(), $key32);
                $uploaded++;
                $bytes += $size;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("failed: {$rel} — ".$this->describeOffboxError($e));
            }
        }

        $pruned = 0;
        if ($failed === 0) {
            try {
                $pruned = $this->pruneDeleted($disk, $prefix, $root, (bool) $this->option('dry-run'));
            } catch (\Throwable $e) {
                $failed++;
                $this->warn('failed to prune deleted files — '.$this->describeOffboxError($e));
            }
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        $ok = $failed === 0;
        $detail = "seen={$seen} uploaded={$uploaded} skipped={$skipped} failed={$failed} pruned={$pruned}";
        $this->recordRun($ok, $seen, $bytes, $ok ? null : "{$failed} file(s) failed to mirror", $detail, $ms);

        if (! $ok) {
            AlertDispatcher::send('backup', "platform media mirror: {$failed} file(s) failed ({$detail})");
            $this->error('platform:media-backup finished with failures: '.$detail);

            return self::FAILURE;
        }

        $this->info('platform:media-backup '.$detail." ({$ms} ms)");

        return self::SUCCESS;
    }

    /**
     * Stream one file to the mirror. With a key it is sealed first into a 0600 temp file (streamed JWBK, 64 KiB
     * frames — never read whole into memory), uploaded, and the temp file removed.
     */
    private function upload(Filesystem $disk, string $key, string $path, ?string $key32): void
    {
        $src = $path;
        $tmp = null;
        if ($key32 !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'jwbk-media-');
            if ($tmp === false) {
                throw new \RuntimeException('cannot create a temp file for encryption');
            }
            $src = $tmp;
        }
        try {
            if ($tmp !== null) {
                BackupCrypto::encryptFile($path, $tmp, (string) $key32);
            }
            $this->putOffbox($disk, $key, $src); // P6-166: a refused or short PUT throws instead of passing silently
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
    }

    /**
     * P1-23: forget mirrored files deleted locally more than keep_days ago. Returns how many were removed.
     */
    private function pruneDeleted(Filesystem $disk, string $prefix, string $root, bool $dryRun): int
    {
        $manifestKey = $prefix.'.deleted.json';
        $missingSince = $disk->exists($manifestKey) ? (array) json_decode((string) $disk->get($manifestKey), true) : [];
        $today = now()->toDateString();
        $cutoff = now()->subDays(max(1, (int) config('backup.keep_days', 30)))->toDateString();

        $next = [];
        $pruned = 0;
        foreach ($disk->allFiles($prefix) as $key) {
            if (is_file($root.'/'.substr($key, strlen($prefix) + 1))) {
                continue;
            }
            $since = (string) ($missingSince[$key] ?? $today);
            if ($since <= $cutoff) {
                if (! $dryRun) {
                    $disk->delete($key);
                }
                $pruned++;

                continue;
            }
            $next[$key] = $since;
        }

        if (! $dryRun) {
            $next === [] ? $disk->delete($manifestKey) : $disk->put($manifestKey, (string) json_encode($next), $this->offboxWriteOptions());
        }

        return $pruned;
    }

    /**
     * M7: durable evidence row (kind = 'media'), never throws — same table and
     * best-effort shape C2 established for platform:db-backup.
     */
    private function recordRun(bool $ok, int $fileCount, int $bytes, ?string $error, ?string $detail = null, ?int $ms = null): void
    {
        try {
            PlatformBackupRun::create([
                'kind' => 'media',
                'ok' => $ok,
                'file_count' => $fileCount ?: null,
                'size_bytes' => $bytes ?: null,
                'mirrored' => $ok,
                'detail' => $detail,
                'duration_ms' => $ms,
                'error' => $error,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // half-migrated box: never break the mirror over its own bookkeeping
        }
    }
}
