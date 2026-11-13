<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Console\Commands\PlatformDbBackup;
use App\Services\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * H14: the platform-backup staleness alert that has never executed.
 *
 * PlatformMetrics::evaluateAlerts() gates its whole backup-freshness block on
 * `DB::connection()->getDriverName() === 'mysql'` (PlatformMetrics.php), so on
 * the sqlite CI driver the "your backups have gone stale" alarm is structurally
 * unreachable — PlatformDbBackupTest even codifies that with
 * test_command_is_a_no_op_on_sqlite. This class runs the alert for real on
 * MySQL; it self-skips off MySQL via TestCase's #[Group('mysql')] guard.
 */
#[Group('mysql')]
class MysqlLanePlatformMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function backupDir(): string
    {
        return storage_path('app/'.PlatformDbBackup::DISK_DIR);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob($this->backupDir().'/db-*.sql.gz') ?: [] as $f) {
            @unlink($f);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir().'/db-*.sql.gz') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_missing_platform_backup_raises_an_alert_on_mysql(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());

        $alerts = PlatformMetrics::evaluateAlerts();

        $this->assertTrue(
            collect($alerts)->contains(fn ($a) => (bool) preg_match('/no platform DB backup found/', $a)),
            'Missing backup must raise the "no platform DB backup found" alert on MySQL. Got: '.json_encode($alerts)
        );
    }

    public function test_a_stale_backup_raises_the_age_alert_on_mysql(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());

        @mkdir($this->backupDir(), 0775, true);
        $file = $this->backupDir().'/db-stale.sql.gz';
        file_put_contents($file, gzencode('-- fake dump'));
        touch($file, time() - 48 * 3600);

        config(['platform.alerts.max_backup_age_hours' => 26]);

        $alerts = PlatformMetrics::evaluateAlerts();

        $this->assertTrue(
            collect($alerts)->contains(fn ($a) => (bool) preg_match('/platform DB backup is \d+h old/', $a)),
            'A 48h-old backup must raise the age alert. Got: '.json_encode($alerts)
        );
    }

    public function test_a_fresh_backup_raises_no_backup_alert(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());

        @mkdir($this->backupDir(), 0775, true);
        $file = $this->backupDir().'/db-fresh.sql.gz';
        file_put_contents($file, gzencode('-- fake dump'));
        touch($file, time());

        config(['platform.alerts.max_backup_age_hours' => 26]);

        $alerts = PlatformMetrics::evaluateAlerts();

        $this->assertFalse(
            collect($alerts)->contains(fn ($a) => (bool) preg_match('/no platform DB backup found/', $a)),
            'A fresh backup must not raise the missing-backup alert.'
        );
        $this->assertFalse(
            collect($alerts)->contains(fn ($a) => (bool) preg_match('/platform DB backup is \d+h old/', $a)),
            'A fresh backup must not raise the age alert.'
        );
    }
}
