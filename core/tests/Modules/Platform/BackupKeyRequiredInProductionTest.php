<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Models\PlatformBackupRun;
use Juwri\Core\Services\Ops\BackupCrypto;
use Tests\TestCase;

/**
 * J45 (P6-204): on a live site (APP_ENV=production) with no BACKUP_ENCRYPTION_KEY, every platform backup STOPS:
 * no dump, no local file, no S3 upload, a failed run row, a 'backup' alert and a non-zero exit. With a key the
 * backup runs as before; outside production a missing key only warns. Tools are faked (pgsql connection +
 * Process::fake), so this runs the same on sqlite, mysql and pgsql test databases.
 */
class BackupKeyRequiredInProductionTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/j45-'.uniqid();
        @mkdir($this->storage.'/app', 0775, true);
        $this->app->useStoragePath($this->storage);
        @mkdir($this->storage.'/media', 0775, true);
        file_put_contents($this->storage.'/media/a.jpg', 'img');

        config([
            'database.connections.pgfake' => [
                'driver' => 'pgsql', 'host' => 'db.internal', 'port' => '5433', 'database' => 'juwri',
                'username' => 'juwri_app', 'password' => 'secret', 'search_path' => 'public', 'sslmode' => 'require',
            ],
            'filesystems.disks.s3.key' => 'AKIATEST',
            'filesystems.disks.s3.bucket' => 'juwri-test',
            'backup.media_root' => $this->storage.'/media',
            'backup.encryption_previous_keys' => '',
        ]);
        Storage::fake('s3');

        Process::fake(function (PendingProcess $process) {
            $cmd = (string) $process->command;
            if (str_contains($cmd, 'pg_dump') && preg_match("/echo \\$\\? > '([^']+)'; } \\| gzip > '([^']+)'/", $cmd, $m)) {
                file_put_contents($m[1], "0\n");
                file_put_contents($m[2], gzencode("CREATE TABLE x ();\n--\n-- PostgreSQL database dump complete\n--\n"));
            }

            return Process::result();
        });
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->storage));
        parent::tearDown();
    }

    private function env(string $env, bool $withKey): void
    {
        config([
            'app.env' => $env,
            'backup.encryption_key' => $withKey ? 'base64:'.base64_encode(random_bytes(32)) : '',
        ]);
    }

    /** @return list<string> */
    private function localDumps(): array
    {
        return array_merge(
            glob($this->storage.'/app/backups/platform/db-*') ?: [],
            glob($this->storage.'/app/backups/platform/*.part') ?: [],
        );
    }

    public function test_production_without_key_stops_the_db_backup_and_alerts(): void
    {
        $this->env('production', false);

        $this->artisan('platform:db-backup', ['--connection' => 'pgfake'])
            ->expectsOutputToContain('BACKUP_ENCRYPTION_KEY is not set')
            ->assertExitCode(1);

        Process::assertNothingRan();
        $this->assertSame([], $this->localDumps(), 'nothing written locally');
        $this->assertSame([], Storage::disk('s3')->allFiles(), 'nothing uploaded');
        $run = PlatformBackupRun::latestOf('backup');
        $this->assertNotNull($run);
        $this->assertFalse((bool) $run->ok);
        $this->assertSame(BackupCrypto::MISSING_KEY_IN_PRODUCTION, $run->error);
        $this->assertTrue(PlatformAlert::where('kind', 'backup')->where('message', 'like', '%BACKUP_ENCRYPTION_KEY is not set%')->exists());
    }

    public function test_production_without_key_does_not_prune_older_dumps(): void
    {
        $this->env('production', false);
        @mkdir($this->storage.'/app/backups/platform', 0775, true);
        $old = $this->storage.'/app/backups/platform/db-2000-01-01_000000.sql.gz';
        file_put_contents($old, 'x');
        touch($old, time() - 90 * 86400);

        $this->artisan('platform:db-backup', ['--connection' => 'pgfake'])->assertExitCode(1);

        $this->assertFileExists($old);
    }

    public function test_production_with_key_backs_up_encrypted_and_mirrors(): void
    {
        $this->env('production', true);

        $this->artisan('platform:db-backup', ['--connection' => 'pgfake'])->assertExitCode(0);

        $files = glob($this->storage.'/app/backups/platform/db-*.sql.gz') ?: [];
        $this->assertCount(1, $files);
        $this->assertSame('JWBK', substr((string) file_get_contents($files[0]), 0, 4), 'sealed with the key');
        $this->assertCount(1, Storage::disk('s3')->allFiles('backups/platform'));
        $this->assertTrue((bool) PlatformBackupRun::latestOf('backup')->ok);
        $this->assertFalse(PlatformAlert::where('kind', 'backup')->where('message', 'like', '%BACKUP_ENCRYPTION_KEY%')->exists());
    }

    public function test_outside_production_without_key_only_warns(): void
    {
        $this->env('local', false);

        $this->artisan('platform:db-backup', ['--connection' => 'pgfake'])
            ->expectsOutputToContain('NOT encrypted')
            ->assertExitCode(0);

        $this->assertCount(1, glob($this->storage.'/app/backups/platform/db-*.sql.gz') ?: []);
        $this->assertTrue((bool) PlatformBackupRun::latestOf('backup')->ok);
        $this->assertFalse(PlatformAlert::where('kind', 'backup')->where('message', 'like', '%BACKUP_ENCRYPTION_KEY%')->exists());
    }

    public function test_production_without_key_stops_the_media_mirror_including_the_queued_admin_run(): void
    {
        $this->env('production', false);

        // The admin "Run media mirror" button queues exactly this (--force).
        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(1);

        $this->assertSame([], Storage::disk('s3')->allFiles(), 'no media left the box');
        $run = PlatformBackupRun::latestOf('media');
        $this->assertFalse((bool) $run->ok);
        $this->assertSame(BackupCrypto::MISSING_KEY_IN_PRODUCTION, $run->error);
        $this->assertTrue(PlatformAlert::where('kind', 'backup')->where('message', 'like', 'platform media mirror%BACKUP_ENCRYPTION_KEY is not set%')->exists());
    }

    public function test_outside_production_media_mirror_without_key_still_runs(): void
    {
        $this->env('local', false);

        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(0);

        $this->assertNotSame([], Storage::disk('s3')->allFiles());
    }
}
