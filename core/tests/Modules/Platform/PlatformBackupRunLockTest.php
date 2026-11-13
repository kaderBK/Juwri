<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Console\Commands\PlatformDbBackup;
use Juwri\Core\Support\RunLock;
use Tests\TestCase;

/** P6-202: the backup / restore drill hold their own run lock, independent of the scheduler mutex. */
class PlatformBackupRunLockTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(RunLock::path(PlatformDbBackup::LOCK));
        @unlink(RunLock::path('platform-db-restore-check'));
        parent::tearDown();
    }

    private function dumpCount(): int
    {
        $dir = storage_path('app/'.PlatformDbBackup::DISK_DIR);

        return count(array_merge(glob($dir.'/db-*.sql.gz') ?: [], glob($dir.'/*.part') ?: []));
    }

    public function test_second_backup_run_exits_cleanly_without_dumping_while_one_is_in_progress(): void
    {
        $held = RunLock::acquire(PlatformDbBackup::LOCK); // the "first run", still dumping
        $this->assertNotNull($held);
        $before = $this->dumpCount();
        $rows = DB::table('platform_backup_runs')->count();

        $this->artisan('platform:db-backup')
            ->expectsOutputToContain('another backup run is in progress')
            ->assertExitCode(0);

        $this->assertSame($before, $this->dumpCount(), 'no artifact written by the second run');
        $this->assertSame($rows, DB::table('platform_backup_runs')->count(), 'no run row from the skipped run');
        $this->assertNull(RunLock::acquire(PlatformDbBackup::LOCK), 'the first run still holds the lock');

        $held->release();
        $again = RunLock::acquire(PlatformDbBackup::LOCK);
        $this->assertNotNull($again, 'released after the first run ends');
        $again->release();
    }

    public function test_stale_lock_file_from_a_crashed_run_does_not_block(): void
    {
        $path = RunLock::path(PlatformDbBackup::LOCK);
        @mkdir(dirname($path), 0775, true);
        // A killed run leaves the file (with its owner line) but no flock: the kernel dropped it on exit.
        file_put_contents($path, json_encode(['pid' => 999999, 'host' => 'dead', 'started_at' => '2000-01-01T00:00:00Z']));

        $this->artisan('platform:db-backup')
            ->doesntExpectOutputToContain('another backup run is in progress');

        $lock = RunLock::acquire(PlatformDbBackup::LOCK);
        $this->assertNotNull($lock, 'the lock is free again after the run');
        $this->assertStringContainsString((string) getmypid(), (string) file_get_contents($path));
        $lock->release();
    }

    public function test_second_restore_drill_is_skipped_while_one_runs(): void
    {
        $held = RunLock::acquire('platform-db-restore-check');
        $this->assertNotNull($held);

        $this->artisan('platform:db-restore-check', ['--force' => true])
            ->expectsOutputToContain('another restore drill is in progress')
            ->assertExitCode(0);

        $held->release();
    }

    public function test_restore_drill_refuses_an_in_flight_part_file(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('the drill is a no-op on sqlite before it picks a file');
        }
        $dir = storage_path('app/'.PlatformDbBackup::DISK_DIR);
        @mkdir($dir, 0775, true);
        $part = $dir.'/db-2099-01-01_000000.sql.gz.part';
        file_put_contents($part, 'half');
        try {
            $this->artisan('platform:db-restore-check', ['--force' => true, '--file' => $part])
                ->expectsOutputToContain('is still being written')
                ->assertExitCode(1);
        } finally {
            @unlink($part);
        }
    }
}
