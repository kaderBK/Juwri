<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Models\PlatformBackupRun;
use App\Services\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * C2: the restore drill and the validity alerts. The command itself early-returns
 * on a non-mysql driver, so the sqlite suite verifies the seams around it: the
 * no-op guard, the settings read-leg (the C1 dead-toggle guard), and the alert
 * helper that evaluateAlerts() calls inside its mysql block.
 */
class PlatformRestoreDrillTest extends TestCase
{
    use RefreshDatabase;

    /** Mirror SettingsServiceProvider::boot() population (which is app_is_installed-gated, off in tests). */
    private function bootSettings(): void
    {
        PlatformSettings::flush();
        foreach (PlatformSettings::legacyMap() as $k => $v) {
            config(['settings.'.$k => Str::isJson($v) ? json_decode($v) : $v]);
        }
    }

    public function test_drill_is_a_no_op_on_sqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The drill really runs on MySQL/MariaDB/PostgreSQL (bug 5); only the sqlite skip is asserted here.');
        }

        $this->artisan('platform:db-restore-check')
            ->expectsOutputToContain('skipped (non-server driver)')
            ->assertExitCode(0);
    }

    public function test_the_settings_toggle_actually_gates_the_drill(): void
    {
        // Read leg: the Option written by the general-settings form under this
        // exact name must surface as config('settings.<same name>'). An Option
        // written under one name and read under another is the C1 dead toggle.
        $this->assertFalse((bool) config('settings.platform_backup_restore_check_enabled'));

        PlatformSettings::set('platform_backup_restore_check_enabled', '1');
        $this->bootSettings();

        $this->assertTrue((bool) config('settings.platform_backup_restore_check_enabled'),
            'the Option must round-trip to the config key the drill reads');
    }

    public function test_metrics_alerts_on_a_rejected_backup(): void
    {
        PlatformBackupRun::create([
            'kind' => 'backup', 'ok' => false,
            'error' => 'the "-- Dump completed" trailer is missing: the dump is truncated',
            'created_at' => now(),
        ]);

        $alerts = PlatformMetrics::backupValidityAlerts();

        $this->assertNotEmpty($alerts);
        $this->assertStringContainsString('REJECTED', implode("\n", $alerts));
    }

    public function test_metrics_are_quiet_when_the_last_backup_is_valid(): void
    {
        PlatformBackupRun::create(['kind' => 'backup', 'ok' => true, 'path' => 'x.sql.gz', 'created_at' => now()]);

        $this->assertSame([], PlatformMetrics::backupValidityAlerts());
    }
}
