<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Console\Commands\PlatformDbRestoreCheck;
use Aws\Command;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Console\Commands\PlatformDbBackup;
use Juwri\Core\Console\Commands\PlatformMediaBackup;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * P6-166: S3 off-box backup drill findings. The `s3` disk is configured with 'throw' => false, so a refused PUT
 * (wrong keys, missing bucket, endpoint down, SSE not supported) came back as `false` and the backup recorded
 * "mirrored" and then pruned. These pin the fixes; a live MinIO drill script covers the real upload.
 */
class OffboxBackupS3Test extends TestCase
{
    private string $local;

    protected function setUp(): void
    {
        parent::setUp();
        $this->local = tempnam(sys_get_temp_dir(), 'p6166-');
        file_put_contents($this->local, str_repeat('x', 1000));
        config(['filesystems.disks.s3.key' => 'AKIADRILLKEYID', 'filesystems.disks.s3.secret' => 'drill-secret-value-123', 'filesystems.disks.s3.bucket' => 'drill']);
    }

    protected function tearDown(): void
    {
        @unlink($this->local);
        foreach (glob(storage_path('app/'.PlatformDbBackup::DISK_DIR).'/.s3-restore-*') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function invoke(object $cmd, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($cmd, $method);
        $ref->setAccessible(true);

        return $ref->invoke($cmd, ...$args);
    }

    private function command(object $cmd, array $input = []): object
    {
        $cmd->setLaravel($this->app);
        $cmd->setInput(new ArrayInput($input, $cmd->getDefinition()));
        $cmd->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        return $cmd;
    }

    public function test_a_refused_put_throws_instead_of_counting_as_mirrored(): void
    {
        foreach ([new PlatformDbBackup, new PlatformMediaBackup] as $cmd) {
            $disk = Mockery::mock(Filesystem::class);
            $disk->shouldReceive('put')->once()->andReturn(false); // what the 'throw' => false disk returns on 403/404
            $disk->shouldNotReceive('size');
            try {
                $this->invoke($cmd, 'putOffbox', $disk, 'backups/platform/db-x.sql.gz', $this->local);
                $this->fail('a false put() must throw');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('the off-box store refused the upload', $e->getMessage());
            }
        }
    }

    public function test_a_short_stored_object_is_a_partial_upload(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('size')->once()->andReturn(512);

        $this->expectExceptionMessage('partial off-box object: 512 of 1000 bytes stored');
        $this->invoke(new PlatformDbBackup, 'putOffbox', $disk, 'backups/platform/db-x.sql.gz', $this->local);
    }

    public function test_a_whole_upload_passes_and_keeps_the_sse_option(): void
    {
        $disk = Storage::fake('s3');
        $this->invoke(new PlatformDbBackup, 'putOffbox', $disk, 'backups/platform/db-x.sql.gz', $this->local);
        $this->assertSame(1000, $disk->size('backups/platform/db-x.sql.gz'));
    }

    public function test_the_off_box_keys_are_redacted_from_every_message(): void
    {
        $msg = $this->invoke(new PlatformDbBackup, 'redactOffbox', 'auth failed for AKIADRILLKEYID with drill-secret-value-123');
        $this->assertSame('auth failed for [redacted] with [redacted]', $msg);
    }

    public function test_store_errors_become_short_key_free_reasons(): void
    {
        $cmd = new PlatformDbBackup;
        $aws = fn (string $msg, array $ctx = [], ?\Throwable $prev = null) => new S3Exception($msg, new Command('PutObject'), $ctx, $prev);

        $missing = UnableToWriteFile::atLocation('k', 'x', $aws('Error executing "PutObject" on "http://s3/drill/k"', ['code' => 'NoSuchBucket', 'response' => new Response(404)]));
        $this->assertSame('bucket missing (NoSuchBucket)', $this->invoke($cmd, 'describeOffboxError', $missing));

        $keys = $aws('AKIADRILLKEYID is not valid', ['code' => 'InvalidAccessKeyId', 'response' => new Response(403)]);
        $this->assertSame('wrong keys or no access (HTTP 403 InvalidAccessKeyId)', $this->invoke($cmd, 'describeOffboxError', $keys));

        $cut = $aws('Error executing "PutObject" on "http://s3/drill/k"', [], new \RuntimeException('cURL error 52: Empty reply from server (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for http://s3/drill/k'));
        $this->assertSame('store unreachable or connection dropped: cURL error 52: Empty reply from server for http://s3/drill/k', $this->invoke($cmd, 'describeOffboxError', $cut));

        $this->assertSame('plain [redacted]', $this->invoke($cmd, 'describeOffboxError', new \RuntimeException('plain drill-secret-value-123')));
    }

    public function test_the_backup_disk_throws_on_real_s3_and_keeps_a_fake(): void
    {
        config(['filesystems.disks.s3.region' => 'us-east-1']);
        $real = $this->invoke(new PlatformDbBackup, 'offboxDisk');
        $this->assertInstanceOf(AwsS3V3Adapter::class, $real);
        $this->assertTrue((new \ReflectionProperty($real, 'config'))->getValue($real)['throw']);
        $this->assertFalse((bool) config('filesystems.disks.s3.throw'), 'the shared s3 disk config is unchanged');

        $fake = Storage::fake('s3');
        $this->assertSame($fake, $this->invoke(new PlatformDbBackup, 'offboxDisk'));
    }

    public function test_offbox_prune_never_deletes_tonights_object(): void
    {
        $disk = Storage::fake('s3');
        $dir = PlatformDbBackup::DISK_DIR;
        $tonight = $dir.'/db-2026-10-14_021500.sql.gz';
        $disk->put($tonight, 'new');
        $disk->put($dir.'/db-2000-01-01_000000.sql.gz', 'old');
        // a store whose clock runs days ahead of this box: both objects look old
        touch($disk->path($tonight), time() - 40 * 86400);
        touch($disk->path($dir.'/db-2000-01-01_000000.sql.gz'), time() - 40 * 86400);

        $deleted = $this->invoke($this->command(new PlatformDbBackup), 'pruneOffbox', 30, $tonight);
        $this->assertSame(1, $deleted);
        $disk->assertExists($tonight);
        $disk->assertMissing($dir.'/db-2000-01-01_000000.sql.gz');
    }

    public function test_local_prune_never_deletes_tonights_dump(): void
    {
        $dir = storage_path('app/'.PlatformDbBackup::DISK_DIR);
        @mkdir($dir, 0775, true);
        $tonight = $dir.'/db-2026-10-14_021500.sql.gz';
        $old = $dir.'/db-2000-01-01_000000.sql.gz';
        foreach ([$tonight, $old] as $f) {
            file_put_contents($f, 'x');
            touch($f, time() - 40 * 86400); // a box clock that jumped forward after the dump
        }
        try {
            $this->invoke($this->command(new PlatformDbBackup), 'prune', 30, $tonight);
            $this->assertFileExists($tonight);
            $this->assertFileDoesNotExist($old);
        } finally {
            @unlink($tonight);
            @unlink($old);
        }
    }

    public function test_restore_check_fetches_the_newest_off_box_dump(): void
    {
        $disk = Storage::fake('s3');
        $dir = PlatformDbBackup::DISK_DIR;
        $disk->put($dir.'/db-2026-10-12_021500.sql.gz', 'older');
        $disk->put($dir.'/db-2026-10-13_021500.sql.gz', 'newest-dump');
        $disk->put($dir.'/db-2026-10-13_021500.sql.gz.part', 'ignored');
        $disk->put($dir.'/media/db-2099-01-01_000000.sql.gz', 'ignored: media prefix');

        $cmd = $this->command(new PlatformDbRestoreCheck, ['--from-s3' => true]);
        $local = $this->invoke($cmd, 'fetchOffbox');

        $this->assertSame('newest-dump', file_get_contents($local));
        $this->assertStringStartsWith('.s3-restore-', basename($local), 'never matches the db-*.sql.gz retention/health glob');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($local)), -4));
    }

    public function test_restore_check_from_s3_fails_loudly_on_an_empty_store_or_a_missing_object(): void
    {
        Storage::fake('s3');
        $cmd = $this->command(new PlatformDbRestoreCheck, ['--from-s3' => true]);
        try {
            $this->invoke($cmd, 'fetchOffbox');
            $this->fail('an empty store must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no db-*.sql.gz object in the off-box store', $e->getMessage());
        }

        $cmd = $this->command(new PlatformDbRestoreCheck, ['--from-s3' => true, '--file' => 'backups/platform/db-nope.sql.gz']);
        $this->expectExceptionMessage('cannot read backups/platform/db-nope.sql.gz');
        $this->invoke($cmd, 'fetchOffbox');
    }

    public function test_restore_check_from_s3_needs_a_target(): void
    {
        config(['filesystems.disks.s3.bucket' => null]);
        $cmd = $this->command(new PlatformDbRestoreCheck, ['--from-s3' => true]);
        $this->expectExceptionMessage('no off-box target configured');
        $this->invoke($cmd, 'fetchOffbox');
    }
}
