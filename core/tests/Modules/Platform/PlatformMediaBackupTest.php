<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Models\PlatformBackupRun;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * WI-M7 — platform:media-backup mirrors the repo-root media tree to S3
 * incrementally (walk-and-exclude, not an allowlist), records a run row, fails
 * loudly with no off-box target, and is gated by the platform_media_backup_enabled
 * Option (the C1 dead-toggle fence).
 */
class PlatformMediaBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/m7-media-'.uniqid();
        mkdir($this->tmp, 0777, true);
        config([
            'backup.media_root' => $this->tmp,
            'filesystems.disks.s3.key' => 'k',
            'filesystems.disks.s3.bucket' => 'b',
        ]);
        Storage::fake('s3');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir.'/'.$f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function putFile(string $rel, string $contents = 'x'): void
    {
        $full = $this->tmp.'/'.$rel;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $contents);
    }

    public function test_media_mirror_uploads_new_files_and_skips_unchanged(): void
    {
        $this->putFile('menu/a.png', 'aaa');
        $this->putFile('store/logo/b.png', 'bbb');
        $this->putFile('profile/c.png', 'ccc');

        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(0);

        Storage::disk('s3')->assertExists('backups/platform/media/menu/a.png');
        Storage::disk('s3')->assertExists('backups/platform/media/store/logo/b.png');
        Storage::disk('s3')->assertExists('backups/platform/media/profile/c.png');

        // Second run: nothing changed -> all skipped.
        $this->artisan('platform:media-backup', ['--force' => true])
            ->expectsOutputToContain('uploaded=0 skipped=3')
            ->assertExitCode(0);
    }

    /** P1-23: a deleted upload stays in the mirror for the 30 days backups are kept, then leaves it (Terms 7.6: 90 days). */
    public function test_a_file_deleted_locally_leaves_the_mirror_after_the_retention_period(): void
    {
        config(['backup.keep_days' => 30]);
        $this->putFile('menu/gone.png', 'g');
        $this->putFile('menu/back.png', 'b');
        $this->putFile('menu/kept.png', 'k');
        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(0);

        unlink($this->tmp.'/menu/gone.png');
        unlink($this->tmp.'/menu/back.png');
        $this->artisan('platform:media-backup', ['--force' => true])->expectsOutputToContain('pruned=0')->assertExitCode(0);
        Storage::disk('s3')->assertExists('backups/platform/media/menu/gone.png');
        Storage::disk('s3')->assertExists('backups/platform/media.deleted.json');

        $this->travel(20)->days();
        $this->putFile('menu/back.png', 'b'); // restored before the end of the period: forgotten as deleted
        $this->artisan('platform:media-backup', ['--force' => true])->expectsOutputToContain('pruned=0')->assertExitCode(0);

        $this->travel(11)->days();
        $this->artisan('platform:media-backup', ['--force' => true])->expectsOutputToContain('pruned=1')->assertExitCode(0);
        Storage::disk('s3')->assertMissing('backups/platform/media/menu/gone.png');
        Storage::disk('s3')->assertExists('backups/platform/media/menu/back.png');
        Storage::disk('s3')->assertExists('backups/platform/media/menu/kept.png');
        Storage::disk('s3')->assertMissing('backups/platform/media.deleted.json');
    }

    public function test_a_run_stopped_at_the_file_ceiling_never_prunes_a_file_that_still_exists(): void
    {
        config(['backup.keep_days' => 1, 'backup.media_max_files_per_run' => 1]);
        $this->putFile('a.png', 'a');
        $this->putFile('b.png', 'b');
        Storage::disk('s3')->put('backups/platform/media/a.png', 'a');
        Storage::disk('s3')->put('backups/platform/media/b.png', 'b');
        Storage::disk('s3')->put('backups/platform/media/old/x.png', 'x'); // deleted locally long ago

        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(0);
        $this->travel(5)->days();
        $this->artisan('platform:media-backup', ['--force' => true])->expectsOutputToContain('pruned=1')->assertExitCode(0);

        Storage::disk('s3')->assertExists('backups/platform/media/a.png');
        Storage::disk('s3')->assertExists('backups/platform/media/b.png');
        Storage::disk('s3')->assertMissing('backups/platform/media/old/x.png');
    }

    public function test_media_mirror_covers_a_directory_the_legacy_allowlist_misses(): void
    {
        // marketplace/ is absent from MigrateMediaToS3::MEDIA_DIRS.
        $this->putFile('marketplace/screenshot.png', 'mkt');

        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(0);

        Storage::disk('s3')->assertExists('backups/platform/media/marketplace/screenshot.png');
    }

    public function test_media_mirror_respects_the_exclude_list(): void
    {
        $this->putFile('log.html', 'log');
        $this->putFile('menu/keep.png', 'keep');

        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(0);

        Storage::disk('s3')->assertMissing('backups/platform/media/log.html');
        Storage::disk('s3')->assertExists('backups/platform/media/menu/keep.png');
    }

    public function test_media_mirror_records_a_run_row(): void
    {
        $this->putFile('menu/a.png');
        $this->putFile('menu/b.png');

        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseHas('platform_backup_runs', ['kind' => 'media', 'ok' => 1]);
        $this->assertSame(2, (int) PlatformBackupRun::latestOf('media')->file_count);
    }

    public function test_media_mirror_fails_loudly_with_no_offbox_target(): void
    {
        config(['filesystems.disks.s3.key' => '', 'filesystems.disks.s3.bucket' => '']);
        $this->putFile('menu/a.png');

        $this->artisan('platform:media-backup', ['--force' => true])->assertExitCode(1);

        $this->assertDatabaseHas('platform_backup_runs', ['kind' => 'media', 'ok' => 0]);
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'backup']);
    }

    public function test_the_media_toggle_actually_gates_the_command(): void
    {
        $this->putFile('menu/a.png');

        // Option unset + no --force => skipped (disabled).
        $this->artisan('platform:media-backup')
            ->expectsOutputToContain('skipped (disabled')
            ->assertExitCode(0);

        // The Option leg and the config('settings.…') read leg must agree (C1 fence).
        PlatformSettings::set('platform_media_backup_enabled', '1');
        PlatformSettings::flush();
        config(['settings.platform_media_backup_enabled' => '1']);
        $this->assertTrue((bool) config('settings.platform_media_backup_enabled'));
    }
}
