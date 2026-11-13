<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Services\PlatformMetrics;
use App\Support\HostCapabilities;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Console\Commands\PlatformDbBackup;
use Juwri\Core\Console\Commands\PlatformMediaBackup;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * O7 — platform DB backup. The command is a clean no-op on sqlite (CI stays
 * green); the freshness helper + retention are driver-independent.
 */
class PlatformDbBackupTest extends TestCase
{
    private function backupDir(): string
    {
        return storage_path('app/'.PlatformDbBackup::DISK_DIR);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir().'/db-*.sql.gz') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_command_is_a_no_op_on_sqlite(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('mysql/mariadb/pgsql really dump (bug 5); only sqlite skips.');
        }
        $this->artisan('platform:db-backup')
            ->expectsOutputToContain('skipped (non-server driver)')
            ->assertExitCode(0);

        $this->assertEmpty(glob($this->backupDir().'/db-*.sql.gz') ?: []);
    }

    public function test_latest_backup_age_helper(): void
    {
        // No dir/file yet → null.
        foreach (glob($this->backupDir().'/db-*.sql.gz') ?: [] as $f) {
            @unlink($f);
        }
        $this->assertNull(PlatformMetrics::latestPlatformBackupAgeHours());

        @mkdir($this->backupDir(), 0775, true);
        $file = $this->backupDir().'/db-2000-01-01_000000.sql.gz';
        file_put_contents($file, 'x');

        $this->assertSame(0, PlatformMetrics::latestPlatformBackupAgeHours(), 'a just-written backup is 0h old');

        // Age an artifact 48h and assert the helper reports it.
        touch($file, time() - 48 * 3600);
        $this->assertGreaterThanOrEqual(47, PlatformMetrics::latestPlatformBackupAgeHours());
    }

    public function test_prune_removes_dumps_older_than_keep(): void
    {
        @mkdir($this->backupDir(), 0775, true);
        $old = $this->backupDir().'/db-2000-01-01_000000.sql.gz';
        $new = $this->backupDir().'/db-2099-01-01_000000.sql.gz';
        file_put_contents($old, 'x');
        file_put_contents($new, 'y');
        touch($old, time() - 30 * 86400); // 30 days old
        touch($new, time());

        $cmd = new PlatformDbBackup;
        $ref = new \ReflectionMethod($cmd, 'prune');
        $ref->setAccessible(true);
        $ref->invoke($cmd, 14); // keep 14 days

        $this->assertFileDoesNotExist($old, 'a 30-day-old dump must be pruned at keep=14');
        $this->assertFileExists($new, 'a fresh dump must survive');
    }

    /** Invoke the private validator by reflection. */
    private function validate(string $file): ?string
    {
        $m = new \ReflectionMethod(PlatformDbBackup::class, 'validateArtifact');
        $m->setAccessible(true);

        return $m->invoke(new PlatformDbBackup, $file);
    }

    /**
     * A unique temp .gz path OUTSIDE the shared backup dir. The no-op test asserts
     * that dir is empty, and paratest may run these methods on parallel workers —
     * a fixture named db-*.sql.gz in that dir would race it.
     */
    private function tmpGz(): string
    {
        return sys_get_temp_dir().'/c2-'.uniqid().'.sql.gz';
    }

    public function test_validate_accepts_a_complete_dump(): void
    {
        $file = $this->tmpGz();
        file_put_contents($file, gzencode("CREATE TABLE x;\n-- Dump completed on 2026-08-05 02:15:00\n"));

        $this->assertNull($this->validate($file), 'a complete dump with the trailer must be accepted');

        @unlink($file);
    }

    public function test_validate_rejects_a_gzip_holding_truncated_sql(): void
    {
        if (! (new HostCapabilities)->processesAllowed()) {
            $this->markTestSkipped('needs proc_open (disabled on this host)');
        }
        // A VALID gzip member whose SQL was cut off — mysqldump died mid-stream.
        $file = $this->tmpGz();
        file_put_contents($file, gzencode("CREATE TABLE x;\nINSERT INTO x VALUES (1),(2),(3"));

        // Pin the verifier's nuance: gzip -t PASSES this file, so a gunzip-only
        // gate would wave it through. The trailer scan is what catches it.
        $gt = new Process(['gzip', '-t', $file]);
        $gt->run();
        $this->assertTrue($gt->isSuccessful(), 'gzip -t passes a valid member holding truncated SQL');

        $reason = $this->validate($file);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('Dump completed', (string) $reason);

        @unlink($file);
    }

    public function test_validate_rejects_a_truncated_gzip_stream(): void
    {
        $file = $this->tmpGz();
        file_put_contents($file, gzencode("CREATE TABLE x;\n-- Dump completed on 2026-08-05 02:15:00\n"));
        // Drop the final 4 bytes (CRC/ISIZE) — a truncated gzip STREAM.
        $fh = fopen($file, 'r+');
        ftruncate($fh, filesize($file) - 4);
        fclose($fh);

        $reason = $this->validate($file);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('gzip integrity', (string) $reason);

        // Keep the code comment honest: PHP's own zlib does NOT flag this file —
        // the pure-PHP tail read still returns the trailer that gzip -t rejects.
        $m = new \ReflectionMethod(PlatformDbBackup::class, 'gzTail');
        $m->setAccessible(true);
        $this->assertStringContainsString('Dump completed', (string) $m->invoke(new PlatformDbBackup, $file, 512));

        @unlink($file);
    }

    public function test_a_dying_mysqldump_is_caught_by_the_status_file(): void
    {
        if (! (new HostCapabilities)->processesAllowed()) {
            $this->markTestSkipped('needs proc_open (disabled on this host)');
        }
        $tmp = sys_get_temp_dir();
        $stub = $tmp.'/fake-mysqldump-'.uniqid();
        file_put_contents($stub, "#!/bin/sh\nprintf 'CREATE TABLE x;\\nINSERT INTO x VALUES (1),(2'\nexit 137\n");
        chmod($stub, 0755);

        $statusFile = $tmp.'/.dump-status-'.uniqid();
        $partial = $tmp.'/db-'.uniqid().'.sql.gz.part';
        @unlink($statusFile);
        @unlink($partial);

        // The exact pipeline shape the fix builds.
        $process = Process::fromShellCommandline(
            '{ '.escapeshellarg($stub).'; echo $? > '.escapeshellarg($statusFile).'; }'
            .' | gzip > '.escapeshellarg($partial)
        );
        $process->run();

        // The whole defect: the pipeline exits 0 (gzip succeeded)…
        $this->assertTrue($process->isSuccessful(), 'the gzip tail of the pipeline exits 0 even when mysqldump dies');
        // …but the status file preserves mysqldump's real 137.
        $this->assertSame('137', trim((string) @file_get_contents($statusFile)));

        @unlink($stub);
        @unlink($statusFile);
        @unlink($partial);
    }

    public function test_prune_ignores_part_files(): void
    {
        @mkdir($this->backupDir(), 0775, true);
        $part = $this->backupDir().'/db-2000-01-01_000000.sql.gz.part';
        $real = $this->backupDir().'/db-2000-01-01_000000.sql.gz';
        file_put_contents($part, 'x');
        file_put_contents($real, 'y');
        touch($part, time() - 30 * 86400);
        touch($real, time() - 30 * 86400);

        $ref = new \ReflectionMethod(PlatformDbBackup::class, 'prune');
        $ref->setAccessible(true);
        $ref->invoke(new PlatformDbBackup, 14);

        $this->assertFileExists($part, 'a .part file must never be pruned — the glob is anchored');
        $this->assertFileDoesNotExist($real, 'a 30-day-old real dump is pruned at keep=14');

        @unlink($part);
    }

    public function test_prune_is_skipped_only_when_a_configured_mirror_failed(): void
    {
        // M7: reflect the pure predicate the command runs so the truth table IS the code.
        $m = new \ReflectionMethod(PlatformDbBackup::class, 'pruneAllowed');
        $m->setAccessible(true);
        $cmd = new PlatformDbBackup;

        // (offboxAttempted, mirrored) => may prune?
        $this->assertTrue($m->invoke($cmd, false, false), 'no off-box target: prune (refusing forever fills the disk)');
        $this->assertTrue($m->invoke($cmd, true, true), 'mirrored: prune');
        $this->assertFalse($m->invoke($cmd, true, false), 'configured and FAILED: do NOT prune — redundancy just dropped');
    }

    /** P1-23: the published promise is "kept for 30 days" — the default retention, local and off-box. */
    public function test_retention_defaults_to_thirty_days_and_the_option_still_overrides_it(): void
    {
        $this->assertSame(30, (int) config('backup.keep_days'));

        $keep = function (array $args): int {
            $cmd = new PlatformDbBackup;
            $cmd->setLaravel($this->app);
            $cmd->setInput(new ArrayInput($args, $cmd->getDefinition()));
            $ref = new \ReflectionMethod($cmd, 'keepDays');
            $ref->setAccessible(true);

            return (int) $ref->invoke($cmd);
        };

        $this->assertSame(30, $keep([]), 'no --keep: the config value (30), not the old hard-coded 14');
        $this->assertSame(7, $keep(['--keep' => '7']));
        config(['backup.keep_days' => 45]);
        $this->assertSame(45, $keep([]));
    }

    /** P1-23: off-box copies used to be kept forever; now they follow the same retention as the local dumps. */
    public function test_offbox_dumps_older_than_the_retention_are_deleted_and_nothing_else(): void
    {
        $disk = Storage::fake('s3');
        $dir = PlatformDbBackup::DISK_DIR;
        $disk->put($dir.'/db-2000-01-01_000000.sql.gz', 'old');
        $disk->put($dir.'/db-2099-01-01_000000.sql.gz', 'new');
        $disk->put($dir.'/notes.txt', 'not a dump');
        $disk->put($dir.'/media/photo.jpg', 'media mirror');
        touch($disk->path($dir.'/db-2000-01-01_000000.sql.gz'), time() - 31 * 86400);
        touch($disk->path($dir.'/notes.txt'), time() - 400 * 86400);
        touch($disk->path($dir.'/media/photo.jpg'), time() - 400 * 86400);

        $cmd = new PlatformDbBackup;
        $cmd->setLaravel($this->app);
        $cmd->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        $ref = new \ReflectionMethod($cmd, 'pruneOffbox');
        $ref->setAccessible(true);

        $this->assertSame(1, $ref->invoke($cmd, 30));
        $disk->assertMissing($dir.'/db-2000-01-01_000000.sql.gz');
        $disk->assertExists($dir.'/db-2099-01-01_000000.sql.gz');
        $disk->assertExists($dir.'/notes.txt');
        $disk->assertExists($dir.'/media/photo.jpg');
    }

    /** P1-23: "encrypted backups" — every off-box object asks for server-side encryption unless switched off. */
    public function test_offbox_objects_ask_for_server_side_encryption(): void
    {
        $cmd = new PlatformDbBackup;
        $ref = new \ReflectionMethod($cmd, 'offboxWriteOptions');
        $ref->setAccessible(true);

        $this->assertSame(['ServerSideEncryption' => 'AES256'], $ref->invoke($cmd));
        config(['backup.offbox_encryption' => 'aws:kms']);
        $this->assertSame(['ServerSideEncryption' => 'aws:kms'], $ref->invoke($cmd));
        config(['backup.offbox_encryption' => '']);
        $this->assertSame([], $ref->invoke($cmd));

        $media = new PlatformMediaBackup;
        $mref = new \ReflectionMethod($media, 'offboxWriteOptions');
        $mref->setAccessible(true);
        config(['backup.offbox_encryption' => 'AES256']);
        $this->assertSame(['ServerSideEncryption' => 'AES256'], $mref->invoke($media), 'the media mirror uses the same options');
        $this->assertStringContainsString('offboxWriteOptions()', (string) file_get_contents(app_path('Core/Console/Commands/PlatformMediaBackup.php')));
        // P6-166: the DB dump uploads through ChecksOffboxDisk::putOffbox(), which applies the same options.
        $this->assertStringContainsString('putOffbox(', (string) file_get_contents(app_path('Core/Console/Commands/PlatformDbBackup.php')));
        $this->assertStringContainsString('$this->offboxWriteOptions()', (string) file_get_contents(app_path('Core/Console/Commands/Concerns/ChecksOffboxDisk.php')));
    }
}
