<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Juwri\Core\Models\PlatformBackupRun;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Ops\BackupCrypto;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * C2: the superadmin Backup Health console. Before this item nothing anywhere
 * showed backup validity — the whole point of the page is to make the failure
 * the audit found visible.
 */
class PlatformBackupHealthPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        view()->share([
            'admin_languages' => collect(),
            'notifications' => collect(),
            'totalUnreadNotifications' => 0,
            'totalUnapprovedComments' => 0,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    private function makeOwner(): User
    {
        return User::factory()->create(['group_id' => '2', 'status' => 1, 'email_verified_at' => now()]);
    }

    public function test_backup_health_page_renders_the_latest_run_and_drill(): void
    {
        PlatformBackupRun::create([
            'kind' => 'backup', 'ok' => false,
            'error' => 'the "-- Dump completed" trailer is missing: the dump is truncated',
            'created_at' => now(),
        ]);
        PlatformBackupRun::create(['kind' => 'restore_check', 'ok' => true, 'created_at' => now()]);

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.backup-health.index'))
            ->assertOk()
            ->assertSee('Dump completed');
    }

    public function test_j45_card_shows_the_plain_fix_when_production_backups_stop_for_a_missing_key(): void
    {
        PlatformBackupRun::create([
            'kind' => 'backup', 'ok' => false,
            'error' => BackupCrypto::MISSING_KEY_IN_PRODUCTION,
            'created_at' => now(),
        ]);

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.backup-health.index'))
            ->assertOk()
            ->assertSee('Backups are stopped: BACKUP_ENCRYPTION_KEY is not set.', false)
            ->assertSee('php artisan backup:generate-key', false);
    }

    public function test_p697_health_page_shows_last_drill_result_and_time(): void
    {
        $at = now()->subDays(2)->startOfMinute();
        PlatformBackupRun::create(['kind' => 'restore_check', 'ok' => false, 'error' => 'sentinel table [orders] is missing', 'created_at' => $at]);
        PlatformSettings::set('platform_backup_restore_check_enabled', '1');
        config(['settings.platform_backup_restore_check_enabled' => '1']);

        $this->actingAs($this->makeAdmin())->get(route('admin.backup-health.index'))
            ->assertOk()
            ->assertSee('<span class="ui-stat__value">Failed</span>', false)
            ->assertSee($at->format('Y-m-d H:i'))
            ->assertSee('sentinel table [orders] is missing', false);
    }

    public function test_run_restore_check_button_queues_the_command(): void
    {
        Queue::fake();

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.backup-health.restore-check'))
            ->assertRedirect();

        Queue::assertPushedOn('heavy', QueuedCommand::class);
    }

    public function test_non_admin_cannot_open_the_page(): void
    {
        $this->actingAs($this->makeOwner())
            ->get(route('admin.backup-health.index'))
            ->assertRedirect();
    }

    public function test_p6166_page_warns_without_an_off_box_target_and_never_shows_the_keys(): void
    {
        config(['filesystems.disks.s3.key' => null, 'filesystems.disks.s3.bucket' => null]);
        $this->actingAs($this->makeAdmin())->get(route('admin.backup-health.index'))
            ->assertOk()
            ->assertSee('No off-box backup target')
            ->assertSee('data-tone="warning"', false);

        config(['filesystems.disks.s3.key' => 'AKIAHEALTHPAGE', 'filesystems.disks.s3.secret' => 'health-page-secret', 'filesystems.disks.s3.bucket' => 'drill-bucket']);
        PlatformBackupRun::create(['kind' => 'backup', 'ok' => true, 'mirrored' => true, 'created_at' => now()]);
        $this->actingAs($this->makeAdmin())->get(route('admin.backup-health.index'))
            ->assertOk()
            ->assertDontSee('No off-box backup target')
            ->assertSee('drill-bucket')
            ->assertDontSee('AKIAHEALTHPAGE')
            ->assertDontSee('health-page-secret');
    }

    public function test_backup_health_page_shows_media_coverage_and_offbox_state(): void
    {
        config(['filesystems.disks.s3.key' => 'k', 'filesystems.disks.s3.bucket' => 'my-bucket']);
        PlatformBackupRun::create(['kind' => 'backup', 'ok' => true, 'mirrored' => false, 'mirror_error' => 's3 mirror failed: InvalidAccessKeyId', 'created_at' => now()]);
        PlatformBackupRun::create(['kind' => 'media', 'ok' => true, 'mirrored' => true, 'file_count' => 26000, 'created_at' => now()]);

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.backup-health.index'))
            ->assertOk()
            ->assertSee(___('Media coverage'))
            ->assertSee('InvalidAccessKeyId')
            ->assertSee(___('Mirror media now'));
    }

    public function test_media_mirror_button_queues_the_command(): void
    {
        Queue::fake();

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.backup-health.media-mirror'))
            ->assertRedirect();

        Queue::assertPushedOn('heavy', QueuedCommand::class);
    }
}
