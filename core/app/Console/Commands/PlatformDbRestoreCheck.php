<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Juwri\Core\Console\Commands\Concerns\ChecksOffboxDisk;
use Juwri\Core\Console\Commands\PlatformDbBackup;
use Juwri\Core\Models\PlatformBackupRun;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Services\Ops\BackupCrypto;
use Juwri\Core\Services\Ops\DatabaseDump;
use Juwri\Core\Services\Ops\PhpMysqlDump;
use Juwri\Core\Support\RunLock;
use Juwri\Modules\Menu\Models\Menu;

/**
 * C2: prove the newest platform dump actually restores. Loads it into a
 * throwaway schema, checks the restored table count against the live schema and
 * a handful of sentinel tables, records the result, then drops the schema.
 *
 * Bug 5: MySQL/MariaDB (mysql client, scratch schema) and PostgreSQL (psql into
 * a scratch database), chosen by the connection's driver (DatabaseDump). A
 * clean no-op on sqlite so CI stays green; any other driver fails clearly.
 * P6-97: ON by default in production (it needs CREATE/DROP DATABASE privileges; a box without them gets a loud
 * failed-drill alert instead of silence), OFF elsewhere; the `platform_backup_restore_check_enabled` setting
 * (superadmin → Settings → General) overrides either way. Encrypted dumps (P6-96) are authenticated in full
 * BEFORE anything loads: a wrong key or a tampered file never reaches the client.
 * P6-166: --from-s3 verifies the OFF-BOX copy (the one that survives losing this box): the newest
 * `db-*.sql.gz` object (or --file=<object key>) is downloaded, size-checked, then restored exactly like a local dump.
 */
class PlatformDbRestoreCheck extends Command
{
    use ChecksOffboxDisk;

    /** P6-166: the object key when the drill verifies the off-box copy (recorded instead of the temp path). */
    private ?string $offboxKey = null;

    protected $signature = 'platform:db-restore-check {--file= : dump to verify (default: the newest)} {--keep-schema} {--force} {--connection= : connection whose dump is verified (default: the default connection)} {--from-s3 : verify the off-box (S3) copy; --file is then an object key}';

    protected $description = 'Restore the newest platform DB dump into a scratch schema and verify it (mysql, mariadb, pgsql)';

    /**
     * P6-202: the weekly schedule's withoutOverlapping(120) does not cover the queued admin button, and two drills at
     * once would DROP/CREATE the same scratch schema under each other. A second drill exits 0 without touching it.
     */
    public function handle(): int
    {
        $lock = RunLock::acquire('platform-db-restore-check');
        if ($lock === null) {
            $this->warn('platform:db-restore-check skipped: another restore drill is in progress');

            return self::SUCCESS;
        }
        try {
            return $this->runDrill();
        } finally {
            $lock->release();
        }
    }

    private function runDrill(): int
    {
        $name = (string) ($this->option('connection') ?: config('database.default'));
        $conn = (array) config('database.connections.'.$name, []);
        $driver = (string) ($conn['driver'] ?? '');

        if ($driver === 'sqlite') {
            $this->warn('platform:db-restore-check skipped (non-server driver): '.DatabaseDump::unsupportedReason($driver, $conn));

            return self::SUCCESS;
        }

        try {
            $dump = DatabaseDump::for($conn);
        } catch (InvalidArgumentException $e) {
            $this->recordRun(false, null, $e->getMessage());
            $this->error('platform:db-restore-check failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force') && ! PlatformBackupRun::restoreCheckEnabled()) {
            $this->warn('platform:db-restore-check skipped (disabled — enable it in Settings → General)');

            return self::SUCCESS;
        }

        $live = $dump->database();
        $suffix = (string) config('backup.restore_check_suffix', '_restore_check');
        $scratch = $live.$suffix;

        /* DESTRUCTIVE-COMMAND FENCE. This command runs DROP DATABASE. Refuse
           outright unless the target is provably a derived scratch name. Both
           conditions are needed: an empty suffix would make scratch === live. */
        if ($live === '' || $suffix === '' || $scratch === $live || ! str_ends_with($scratch, $suffix)) {
            $this->error('refusing to run: scratch schema name is not safely derived from the live database');

            return self::FAILURE;
        }

        $offboxTemp = null;
        if ($this->option('from-s3')) {
            try {
                $offboxTemp = $this->fetchOffbox();
            } catch (\Throwable $e) {
                $reason = 'off-box copy could not be fetched: '.$this->describeOffboxError($e);
                $this->recordRun(false, null, $reason);
                AlertDispatcher::send('backup', 'platform DB restore drill FAILED: '.$reason);
                $this->error('restore drill failed: '.$reason);

                return self::FAILURE;
            }
            $file = $offboxTemp;
        } else {
            $file = (string) ($this->option('file') ?: $this->newestDump());
            /* P6-202: never read an artifact still being written. PlatformDbBackup writes `<name>.part` (and `.part.enc`
               while sealing) and only renames a validated file to db-*.sql.gz; newestDump() globs only published names,
               and an explicit --file pointing at an in-flight artifact is refused here. */
            if ($file !== '' && (str_ends_with($file, '.part') || str_ends_with($file, '.part.enc'))) {
                $this->recordRun(false, null, 'refusing an in-flight backup artifact: '.basename($file));
                $this->error('platform:db-restore-check failed: '.basename($file).' is still being written');

                return self::FAILURE;
            }
        }
        if ($file === '' || ! is_file($file)) {
            $this->recordRun(false, null, 'no dump found to verify');

            return self::FAILURE;
        }

        try {
            return $this->verifyFile($name, $conn, $dump, $scratch, $file);
        } finally {
            if ($offboxTemp !== null) {
                @unlink($offboxTemp);
            }
        }
    }

    /** @param  array<string, mixed>  $conn */
    private function verifyFile(string $name, array $conn, DatabaseDump $dump, string $scratch, string $file): int
    {
        /* P6-96: an encrypted dump is decrypted (and so authenticated end to end) into a 0600 temp gzip BEFORE the
           scratch schema is touched; a wrong/missing key or any tampering fails here, loudly, with nothing loaded. */
        $source = $file;
        $plainTemp = null;
        if (BackupCrypto::isEncrypted($file)) {
            $plainTemp = dirname($file).'/.restore-'.bin2hex(random_bytes(6)).'.sql.gz';
            try {
                BackupCrypto::decryptFile($file, $plainTemp);
            } catch (\Throwable $e) {
                @unlink($plainTemp);
                $this->recordRun(false, $file, $e->getMessage());
                AlertDispatcher::send('backup', 'platform DB restore drill FAILED: '.$e->getMessage());
                $this->error('restore drill failed: '.$e->getMessage());

                return self::FAILURE;
            }
            $source = $plainTemp;
        }

        try {
            return $this->restoreFrom($name, $conn, $dump, $scratch, $file, $source);
        } finally {
            if ($plainTemp !== null) {
                @unlink($plainTemp);
            }
        }
    }

    /** @param  array<string, mixed>  $conn */
    private function restoreFrom(string $name, array $conn, DatabaseDump $dump, string $scratch, string $file, string $source): int
    {
        $started = microtime(true);
        $env = $dump->env(); // the password travels in MYSQL_PWD / PGPASSWORD, never argv

        if ($dump->usePhpFallback()) {
            // P6-119a: no proc_open / no mysql client: create, load and drop through PDO.
            $problem = $this->phpRestore($name, $conn, $dump, $file, $source, $scratch);
            if ($problem === false) {
                return self::FAILURE;
            }
        } else {
            // 1. (re)create the scratch schema / database
            $create = Process::timeout(300)->env($env)->run($dump->recreateScratchCommand($scratch));
            if (! $create->successful()) {
                $err = trim($create->errorOutput());
                $this->recordRun(false, $file, 'cannot create scratch schema (privileges?): '.$err);
                AlertDispatcher::send('backup', 'restore drill could not run: '.$err);

                return self::FAILURE;
            }

            // 2. load. Same pipeline hazard, mirrored: here the LAST command is
            //    the client (mysql / psql with ON_ERROR_STOP, whose status we want),
            //    so the status file catches gzip.
            $statusFile = dirname($file).'/.restore-status';
            @unlink($statusFile);
            $load = Process::timeout(3600)->env($env)->run(
                '{ gzip -dc '.escapeshellarg($source).'; echo $? > '.escapeshellarg($statusFile).'; }'
                .' | '.$dump->loadCommand($scratch)
            );
            $gzStatus = is_file($statusFile) ? (int) trim((string) @file_get_contents($statusFile)) : 1;
            @unlink($statusFile);

            $problem = null;
            if (! $load->successful()) {
                $problem = $dump->clientTool().' refused the dump: '.trim($load->errorOutput());
            } elseif ($gzStatus !== 0) {
                $problem = "gunzip exited {$gzStatus} while feeding the restore";
            } else {
                $problem = $this->verifyRestored($name, $conn, $dump, $scratch);
            }
        }

        if (! $this->option('keep-schema')) {
            // PostgreSQL refuses to drop a database with open sessions: close the probe first.
            DB::purge('restore_check');
            if ($dump->usePhpFallback()) {
                DB::connection($name)->statement('DROP DATABASE IF EXISTS '.$this->ident($scratch));
            } else {
                Process::timeout(600)->env($env)->run($dump->dropScratchCommand($scratch));
            }
        }

        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($problem !== null) {
            $this->recordRun(false, $file, $problem, $ms);
            AlertDispatcher::send('backup', 'platform DB restore drill FAILED: '.$problem);
            $this->error('restore drill failed: '.$problem);

            return self::FAILURE;
        }

        $this->recordRun(true, $file, null, $ms);
        $this->info('restore drill passed for '.($this->offboxKey !== null ? 's3:'.$this->offboxKey : basename($file))." ({$ms} ms)");

        return self::SUCCESS;
    }

    /**
     * Returns null when the restored schema looks like the live one, else the reason it does not.
     *
     * @param  array<string, mixed>  $conn
     */
    private function verifyRestored(string $name, array $conn, DatabaseDump $dump, string $scratch): ?string
    {
        config(['database.connections.restore_check' => array_merge($conn, ['database' => $scratch])]);
        DB::purge('restore_check');
        $probe = DB::connection('restore_check');

        // information_schema is a SERVER catalog, not an app table — no prefix
        // applies and the schema/database names are BOUND, never interpolated.
        [$sql, $bindings] = $dump->tableCountQuery((string) $conn['database']);
        $liveTables = (int) DB::connection($name)->selectOne($sql, $bindings)->c;
        [$sql, $bindings] = $dump->tableCountQuery($scratch);
        $restoredTables = (int) $probe->selectOne($sql, $bindings)->c;

        if ($liveTables > 0 && $restoredTables < (int) floor($liveTables * 0.95)) {
            return "restored schema has {$restoredTables} tables, live has {$liveTables}";
        }

        // Sentinels: table names come from the models so the qrtest_ prefix and
        // this repo's singular table names are both handled for us.
        foreach ([User::class, Store::class, Menu::class, Order::class] as $model) {
            $table = (new $model)->getTable();
            try {
                $probe->table($table)->count();
            } catch (\Throwable $e) {
                return "sentinel table [{$table}] is missing from the restored dump";
            }
        }

        if ($probe->table((new User)->getTable())->count() < 1) {
            return 'restored dump contains no users — it is structurally present but empty';
        }

        return null;
    }

    /**
     * P6-119a: the PHP-only restore. Returns null (restored and verified), a problem string, or false when the
     * scratch schema could not even be created (already recorded and alerted). $file is the artifact (recorded),
     * $source the plain gzip to load (the decrypted temp copy of an encrypted dump).
     *
     * @param  array<string, mixed>  $conn
     */
    private function phpRestore(string $name, array $conn, DatabaseDump $dump, string $file, string $source, string $scratch): string|false|null
    {
        try {
            DB::connection($name)->statement('DROP DATABASE IF EXISTS '.$this->ident($scratch));
            DB::connection($name)->statement('CREATE DATABASE '.$this->ident($scratch));
        } catch (\Throwable $e) {
            $this->recordRun(false, $file, 'cannot create scratch schema (privileges?): '.$e->getMessage());
            AlertDispatcher::send('backup', 'restore drill could not run: '.$e->getMessage());

            return false;
        }

        config(['database.connections.restore_check' => array_merge($conn, ['database' => $scratch])]);
        DB::purge('restore_check');
        try {
            (new PhpMysqlDump(DB::connection('restore_check')->getPdo()))->restoreFrom($source); // decrypted temp when encrypted
        } catch (\Throwable $e) {
            return 'the PHP loader refused the dump: '.$e->getMessage();
        }

        return $this->verifyRestored($name, $conn, $dump, $scratch);
    }

    private function ident(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /**
     * P6-166: download the newest off-box dump (or the --file object key) to a 0600 temp file beside the local dumps.
     * The temp name never matches the `db-*.sql.gz` glob, so retention and health never see it. Throws with the
     * store's reason on a missing bucket, wrong keys, an unreachable store or a short download.
     */
    private function fetchOffbox(): string
    {
        if (! $this->s3Configured()) {
            throw new \RuntimeException('no off-box target configured (AWS_ACCESS_KEY_ID / AWS_BUCKET unset)');
        }
        $disk = $this->offboxDisk();
        $dir = PlatformDbBackup::DISK_DIR;
        $key = (string) ($this->option('file') ?: '');
        if ($key === '') {
            foreach ($disk->files($dir) as $path) {
                // db-YYYY-MM-DD_HHMMSS.sql.gz: the name sorts by time, no per-object metadata call needed
                if (preg_match('#^'.preg_quote($dir, '#').'/db-[^/]+\.sql\.gz$#', $path) && strcmp($path, $key) > 0) {
                    $key = $path;
                }
            }
            if ($key === '') {
                throw new \RuntimeException('no db-*.sql.gz object in the off-box store: '.$this->offboxDiagnose($disk));
            }
        }
        $this->offboxKey = $key;

        $in = $disk->readStream($key);
        if (! is_resource($in)) {
            throw new \RuntimeException("cannot read {$key}: ".$this->offboxDiagnose($disk));
        }
        $local = storage_path('app/'.$dir).'/.s3-restore-'.bin2hex(random_bytes(6)).'.sql.gz';
        @mkdir(dirname($local), 0775, true);
        $out = @fopen($local, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('cannot write the downloaded copy');
        }
        @chmod($local, 0600);
        try {
            $copied = stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
        $expected = (int) $disk->size($key);
        if ($copied === false || $copied !== $expected || $expected === 0) {
            @unlink($local);
            throw new \RuntimeException("partial download of {$key}: ".(int) $copied." of {$expected} bytes");
        }
        $this->line("fetched {$key} from the off-box store ({$expected} bytes)");

        return $local;
    }

    /** Newest local platform dump, or '' when none exists. */
    private function newestDump(): string
    {
        $dir = storage_path('app/'.PlatformDbBackup::DISK_DIR);
        $newest = '';
        $newestMtime = -1;
        foreach (glob($dir.'/db-*.sql.gz') ?: [] as $file) {
            $mtime = (int) @filemtime($file);
            if ($mtime > $newestMtime) {
                $newestMtime = $mtime;
                $newest = $file;
            }
        }

        return $newest;
    }

    /** C2: durable evidence row for a restore drill. Never throws. */
    private function recordRun(bool $ok, ?string $path, ?string $error, int $ms = 0): void
    {
        try {
            PlatformBackupRun::create([
                'kind' => 'restore_check',
                'ok' => $ok,
                'path' => $this->offboxKey !== null ? 's3:'.$this->offboxKey : $path,
                'error' => $error !== null ? $this->redactOffbox($error) : null,
                'duration_ms' => $ms ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // bookkeeping must never mask the drill's own result
        }
    }
}
