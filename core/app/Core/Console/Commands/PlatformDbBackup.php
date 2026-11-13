<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use App\Support\HostCapabilities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Juwri\Core\Console\Commands\Concerns\ChecksOffboxDisk;
use Juwri\Core\Models\PlatformBackupRun;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Services\Ops\BackupCrypto;
use Juwri\Core\Services\Ops\DatabaseDump;
use Juwri\Core\Services\Ops\PhpMysqlDump;
use Juwri\Core\Support\RunLock;

/**
 * O7 — platform DB backup. Dump the whole database to a gzipped artifact (P6-96: then encrypted with
 * BACKUP_ENCRYPTION_KEY when set, see BackupCrypto)
 * under storage/app/backups/platform/, prune old dumps, and (when AWS creds
 * exist) mirror to S3. Bug 5: mysqldump on MySQL/MariaDB, pg_dump (plain SQL)
 * on PostgreSQL, chosen by the connection's driver (DatabaseDump). SQLite is a
 * no-op that says to copy the database file (CI stays green); any other driver
 * fails with a clear reason.
 * Scheduled daily; O3's onFailure hook alerts on failure and
 * PlatformMetrics raises a stale-backup alert when the newest dump ages out.
 */
class PlatformDbBackup extends Command
{
    use ChecksOffboxDisk;

    protected $signature = 'platform:db-backup {--keep= : days to keep dumps, local and off-box (default: config backup.keep_days, 30)} {--connection= : connection to dump (default: the default connection)}';

    protected $description = 'Dump the whole platform DB to a gzipped, retained backup (mysql, mariadb, pgsql)';

    public const DISK_DIR = 'backups/platform';

    /** P6-202: RunLock name shared by every entry point (scheduler, manual, queued). */
    public const LOCK = 'platform-db-backup';

    /**
     * P6-202: Kernel's withoutOverlapping(40) can expire during a long PHP-only dump and does not cover a manual or
     * queued run, so the command holds its own RunLock (flock, freed on exit or crash) for the whole dump. A second
     * run while one is in progress exits 0 without dumping, pruning or writing a run row.
     */
    public function handle(): int
    {
        $lock = RunLock::acquire(self::LOCK);
        if ($lock === null) {
            $this->warn('platform:db-backup skipped: another backup run is in progress ('.RunLock::owner(self::LOCK).')');

            return self::SUCCESS;
        }
        try {
            return $this->runBackup();
        } finally {
            $lock->release();
        }
    }

    private function runBackup(): int
    {
        /* J45 (P6-204): a live site without BACKUP_ENCRYPTION_KEY stops BEFORE anything is dumped, so no plaintext
           copy is written locally or mirrored off-box. Failed run row + 'backup' alert + non-zero exit; prune does
           not run (yesterday's dumps stay). Non-production keeps the plain-gzip warning below. */
        if (BackupCrypto::missingInProduction()) {
            $this->recordRun('backup', false, null, 0, BackupCrypto::MISSING_KEY_IN_PRODUCTION);
            AlertDispatcher::send('backup', 'platform DB backup '.BackupCrypto::MISSING_KEY_IN_PRODUCTION);
            $this->error('platform:db-backup failed: '.BackupCrypto::MISSING_KEY_IN_PRODUCTION);

            return self::FAILURE;
        }

        $name = (string) ($this->option('connection') ?: config('database.default'));
        $conn = (array) config('database.connections.'.$name, []);
        $driver = (string) ($conn['driver'] ?? '');

        if ($driver === 'sqlite') {
            $this->warn('platform:db-backup skipped (non-server driver): '.DatabaseDump::unsupportedReason($driver, $conn));

            return self::SUCCESS;
        }

        try {
            $dump = DatabaseDump::for($conn);
        } catch (InvalidArgumentException $e) {
            $this->recordRun('backup', false, null, 0, $e->getMessage());
            $this->error('platform:db-backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $date = now()->format('Y-m-d_His');
        $relative = self::DISK_DIR."/db-{$date}.sql.gz";
        $absolute = storage_path('app/'.$relative);

        @mkdir(dirname($absolute), 0775, true);

        /* C2: write to `<name>.part` first. `prune()` and
           PlatformMetrics::latestPlatformBackupAgeHours() both glob `db-*.sql.gz`,
           and a glob pattern is anchored at both ends — `db-….sql.gz.part` does
           NOT match it. So an in-flight or rejected artifact can never be counted
           as "today's backup" and can never be pruned as if it were one. */
        $partial = $absolute.'.part';
        $statusFile = dirname($absolute).'/.dump-status-'.$date;
        @unlink($partial);
        @unlink($statusFile);

        if ($dump->usePhpFallback()) {
            // P6-119a: no proc_open / no mysqldump (shared hosting): PHP-only dump, same artifact and trailer.
            $this->line('using the PHP-only dumper (proc_open or mysqldump unavailable, or BACKUP_DUMP_ENGINE=php)');
            $reject = $this->phpDump($conn, $partial, $dump->completionMarker());
        } else {
            // Whole DB, never a hardcoded table list. Password via env (MYSQL_PWD / PGPASSWORD), never argv.
            $dumpCmd = $dump->dumpCommand();

            /* C2: the old form was `mysqldump … | gzip > file`. A shell pipeline
               returns the LAST command's status, so this reported GZIP's success and a
               mysqldump that died mid-stream still exited 0.

               `set -o pipefail` is NOT the fix here: proc_open() always runs the string
               through `/bin/sh -c`, and on Debian/Ubuntu — the hosting this product
               targets — /bin/sh is dash, where `-o pipefail` is not guaranteed and
               older builds abort with "Illegal option -o pipefail". The command would
               then fail on the shell rather than on the dump.

               The brace-group + status-file idiom below is plain POSIX, works on every
               /bin/sh, and keeps the stream (no uncompressed copy of the whole DB on
               disk). `echo $?` inside the group is mysqldump's status, and its own
               stdout is redirected to the file so it never pollutes the gzip stream. */
            $process = Process::timeout(1800)->env($dump->env())->run(
                '{ '.$dumpCmd.'; echo $? > '.escapeshellarg($statusFile).'; }'
                .' | gzip > '.escapeshellarg($partial)
            );

            $dumpStatus = is_file($statusFile)
                ? (int) trim((string) @file_get_contents($statusFile))
                : 1; // no status file at all == the group never completed
            @unlink($statusFile);

            $reject = null;
            if (! $process->successful()) {
                $reject = 'shell/gzip failed: '.trim($process->errorOutput());
            } elseif ($dumpStatus !== 0) {
                $reject = $dump->dumpTool()." exited {$dumpStatus}: ".trim($process->errorOutput());
            } else {
                $reject = $this->validateArtifact($partial, $dump->completionMarker());
            }
        }

        if ($reject !== null) {
            @unlink($partial);
            $this->recordRun('backup', false, null, 0, $reject);
            AlertDispatcher::send('backup', 'platform DB backup REJECTED: '.$reject);
            $this->error('platform:db-backup failed: '.$reject);

            /* C2: prune() is NOT called on this path. Deleting yesterday's valid
               dumps because today's dump failed is the exact shape of the finding. */
            return self::FAILURE;
        }

        /* P6-96: encrypt the VALIDATED gzip (validation needs the plaintext stream) when a key is set. The name
           keeps `.sql.gz` so retention, health and staleness globs are unchanged; restore tells the formats apart
           by the JWBK header. A malformed key or a failed encryption rejects the night's dump — never falls back
           to publishing plaintext on a box that asked for encryption. */
        try {
            $keyId = $this->seal($partial);
        } catch (\Throwable $e) {
            @unlink($partial);
            @unlink($partial.'.enc');
            $reject = 'encryption failed: '.$e->getMessage();
            $this->recordRun('backup', false, null, 0, $reject);
            AlertDispatcher::send('backup', 'platform DB backup REJECTED: '.$reject);
            $this->error('platform:db-backup failed: '.$reject);

            return self::FAILURE;
        }

        // Valid: publish atomically. Only now does it match the db-*.sql.gz glob.
        if (! @rename($partial, $absolute)) {
            @unlink($partial);
            $this->recordRun('backup', false, null, 0, 'could not publish the validated artifact');
            AlertDispatcher::send('backup', 'platform DB backup REJECTED: rename into place failed');
            $this->error('platform:db-backup failed: could not rename '.$partial);

            return self::FAILURE;
        }
        clearstatcache(true, $absolute);

        $this->info("platform backup written: {$relative} (".$this->human(filesize($absolute)).')'
            .($keyId !== null ? " encrypted, key id {$keyId}" : ''));
        if ($keyId === null) {
            $this->warn('backup is NOT encrypted: BACKUP_ENCRYPTION_KEY is unset (allowed outside production only; php artisan backup:generate-key)');
        }

        /* M7: the off-box copy is the whole point of a backup — a box that dies
           takes its local dumps with it. Three changes from the warn()-only
           original: (1) a FAILED mirror fails the command, so Kernel's onFailure
           hook (SchedulerAlert -> AlertDispatcher -> a platform_alerts row)
           actually fires; (2) a failed mirror SKIPS prune() — deleting older
           dumps on the one night the off-box copy was lost is backwards;
           (3) "no off-box target configured at all" is reported rather than
           silently skipped. */
        $mirrored = false;
        $mirrorError = null;
        $offboxAttempted = $this->s3Configured();

        if ($offboxAttempted) {
            try {
                // P6-166: putOffbox() fails on a refused PUT (the disk does not throw) and on a short stored object.
                $this->putOffbox($this->offboxDisk(), $relative, $absolute);
                $mirrored = true;
                $this->info('mirrored to s3');
            } catch (\Throwable $e) {
                $mirrorError = substr('s3 mirror failed: '.$this->redactOffbox($e->getMessage()), 0, 190);
                $this->error($mirrorError);
            }
        } else {
            $mirrorError = 'no off-box target configured (AWS_ACCESS_KEY_ID / AWS_BUCKET unset)';
            $this->warn($mirrorError.' — every dump is on the disk it backs up');
        }

        $this->recordRun('backup', true, $relative, (int) filesize($absolute), null, [
            'mirrored' => $mirrored,
            'mirror_error' => $mirrorError,
        ]);

        if ($offboxAttempted && ! $mirrored) {
            AlertDispatcher::send('backup', 'platform DB backup: '.$mirrorError.' — retention SKIPPED to preserve redundancy');

            return self::FAILURE;
        }

        if (! $offboxAttempted) {
            AlertDispatcher::send('backup', 'platform DB backups have no off-box copy: set AWS_ACCESS_KEY_ID / AWS_BUCKET');
        }

        if ($this->pruneAllowed($offboxAttempted, $mirrored)) {
            $this->prune($this->keepDays(), $absolute);
            if ($mirrored) {
                $this->pruneOffbox($this->keepDays(), $relative);
            }
        }

        return self::SUCCESS;
    }

    /**
     * C2: prove the artifact is a COMPLETE dump, not merely a non-empty file.
     * Returns null when valid, else the human reason it was rejected.
     *
     * BOTH checks are required — neither subsumes the other:
     *  - `gzip -t` catches a truncated gzip STREAM. PHP's own zlib does not:
     *    measured 2026-08-05, a .gz with its final 4 bytes (CRC/ISIZE) removed
     *    streamed to completion through gzread()/gzeof() with no error at all,
     *    while the gzip binary exited 1.
     *  - the `-- Dump completed` trailer catches mysqldump dying mid-stream,
     *    which yields a perfectly VALID gzip member holding truncated SQL —
     *    the case the audit's verifier singled out, where `gzip -t` passes.
     *
     * Bug 5: pg_dump's plain format ends with `-- PostgreSQL database dump
     * complete`, passed in as $marker (DatabaseDump::completionMarker()).
     *
     * NEVER add `--compact` or `--skip-comments` to the dump flags: either one
     * deletes the trailer this gate depends on and it would then reject every
     * dump forever. `--skip-dump-date` is safe (the line becomes a bare
     * `-- Dump completed`), which is why the check is a prefix `str_contains`.
     */
    private function validateArtifact(string $file, string $marker = '-- Dump completed'): ?string
    {
        clearstatcache(true, $file);
        if (! is_file($file) || filesize($file) === 0) {
            return 'artifact missing or empty';
        }

        if (app(HostCapabilities::class)->processesAllowed()) {
            $test = Process::timeout(900)->run('gzip -t '.escapeshellarg($file));
            if (! $test->successful()) {
                return 'gzip integrity check failed: '.trim($test->errorOutput());
            }
        } elseif (! PhpMysqlDump::gzipComplete($file)) { // P6-140: proc_open disabled, so no `gzip -t`; the PHP check reads the whole stream
            return 'gzip integrity check failed: the stream is incomplete';
        }

        if (! str_contains($this->gzTail($file), $marker)) {
            return 'the "'.$marker.'" trailer is missing: the dump is truncated';
        }

        return null;
    }

    /**
     * P6-119a: dump through PhpMysqlDump on a dedicated connection (its snapshot transaction and unbuffered reads
     * never touch the default connection), then the same completeness gate without spawning `gzip -t`.
     *
     * @param  array<string, mixed>  $conn
     */
    private function phpDump(array $conn, string $partial, string $marker): ?string
    {
        $name = 'platform_php_dump';
        config(['database.connections.'.$name => $conn]);
        DB::purge($name);
        try {
            (new PhpMysqlDump(DB::connection($name)->getPdo()))->dumpTo($partial);
        } catch (\Throwable $e) {
            return 'PHP dump failed: '.$e->getMessage();
        } finally {
            DB::purge($name);
        }

        clearstatcache(true, $partial);
        if (! is_file($partial) || filesize($partial) === 0) {
            return 'artifact missing or empty';
        }
        if (! PhpMysqlDump::gzipComplete($partial)) {
            return 'gzip integrity check failed: the stream is incomplete';
        }
        if (! str_contains($this->gzTail($partial), $marker)) {
            return 'the "'.$marker.'" trailer is missing: the dump is truncated';
        }

        return null;
    }

    /** Last $bytes of the DECOMPRESSED stream, read incrementally (never loads the dump). */
    private function gzTail(string $file, int $bytes = 512): string
    {
        $h = @gzopen($file, 'rb');
        if (! $h) {
            return '';
        }
        $tail = '';
        while (! gzeof($h)) {
            $chunk = gzread($h, 262144);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $tail = substr($tail.$chunk, -$bytes);
        }
        gzclose($h);

        return $tail;
    }

    /**
     * P6-96: encrypt the validated gzip at $partial in place when BACKUP_ENCRYPTION_KEY is set (verified by a full
     * decrypt pass before it replaces the plaintext). Returns the key id, or null when no key is configured (the
     * artifact stays plain gzip). Throws on a malformed key or any failure.
     */
    private function seal(string $partial): ?string
    {
        $key = BackupCrypto::currentKey();
        if ($key === null) {
            return null;
        }
        $sealed = $partial.'.enc';
        try {
            BackupCrypto::encryptFile($partial, $sealed, $key);
            BackupCrypto::verifyFile($sealed);
            if (! @rename($sealed, $partial)) {
                throw new \RuntimeException('could not move the encrypted artifact into place');
            }
        } finally {
            @unlink($sealed);
        }

        return BackupCrypto::keyId($key);
    }

    /**
     * C2: append a durable evidence row. Never throws.
     *
     * @param  array<string, mixed>  $extra
     */
    private function recordRun(string $kind, bool $ok, ?string $path, int $bytes, ?string $error, array $extra = []): void
    {
        try {
            PlatformBackupRun::create(array_merge([
                'kind' => $kind,
                'ok' => $ok,
                'path' => $path,
                'size_bytes' => $bytes ?: null,
                'error' => $error,
                'created_at' => now(),
            ], $extra));
        } catch (\Throwable $e) {
            // half-migrated box: never break the backup over its own bookkeeping
        }
    }

    /** P1-23: retention in days — the --keep option, else config backup.keep_days (30, the published promise). */
    private function keepDays(): int
    {
        $opt = $this->option('keep');

        return max(1, ($opt !== null && $opt !== '') ? (int) $opt : (int) config('backup.keep_days', 30));
    }

    /**
     * P1-23: delete OFF-BOX dumps older than $keepDays. Before this the S3 copies were never pruned, so a deleted
     * account's data stayed in the backups forever (the Terms promise "deleted from backups within 90 days", the
     * security page "kept for 30 days"). Only `db-*.sql.gz` objects under DISK_DIR are touched, and only after the
     * night's own dump reached the off-box store (the caller checks), so the newest copy is never the one removed.
     * Returns how many objects were deleted; a listing/delete failure is reported, never thrown.
     */
    private function pruneOffbox(int $keepDays, ?string $keep = null): int
    {
        $cutoff = now()->subDays(max(1, $keepDays))->getTimestamp();
        $deleted = 0;
        try {
            $disk = $this->offboxDisk();
            foreach ($disk->files(self::DISK_DIR) as $path) {
                if (! preg_match('#^'.preg_quote(self::DISK_DIR, '#').'/db-[^/]+\.sql\.gz$#', $path)) {
                    continue;
                }
                if ($path === $keep) {
                    continue; // P6-166: tonight's verified copy is never pruned, whatever the store's clock says
                }
                if ($disk->lastModified($path) < $cutoff && $disk->delete($path)) {
                    $deleted++;
                }
            }
        } catch (\Throwable $e) {
            $this->warn('off-box retention skipped: '.$this->redactOffbox($e->getMessage()));
        }

        return $deleted;
    }

    /** Delete local dumps older than $keepDays; $keep (tonight's verified dump) is never deleted (P6-166). */
    private function prune(int $keepDays, ?string $keep = null): void
    {
        $dir = storage_path('app/'.self::DISK_DIR);
        if (! is_dir($dir)) {
            return;
        }
        $cutoff = now()->subDays(max(1, $keepDays))->getTimestamp();
        foreach (glob($dir.'/db-*.sql.gz') ?: [] as $file) {
            if ($file !== $keep && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * M7: may retention run tonight? A configured-but-FAILED off-box copy must
     * not be followed by deleting older local dumps — that is the one night
     * redundancy dropped. A box with NO off-box target still prunes: refusing
     * forever would fill the disk, which is a worse outage than the bug.
     */
    private function pruneAllowed(bool $offboxAttempted, bool $mirrored): bool
    {
        return ! $offboxAttempted || $mirrored;
    }

    private function human(int $bytes): string
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($u) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).$u[$i];
    }
}
