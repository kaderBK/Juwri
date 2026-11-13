<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Services\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O8 — queue depth / oldest-job-age. evaluateAlerts raises depth + age alerts
 * over threshold (database driver only); /healthz surfaces per-queue stats.
 */
class QueueDepthTest extends TestCase
{
    use RefreshDatabase;

    private function seedJobs(string $queue, int $n, int $ageSeconds): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [
                'queue' => $queue, 'payload' => '{}', 'attempts' => 0,
                'reserved_at' => null, 'available_at' => time() - $ageSeconds,
                'created_at' => time() - $ageSeconds,
            ];
        }
        DB::table('jobs')->insert($rows);
    }

    public function test_queue_stats_empty_off_database_driver(): void
    {
        config(['queue.default' => 'sync']);
        $this->seedJobs('default', 3, 10);

        $this->assertSame([], PlatformMetrics::queueStats());
    }

    public function test_stats_and_alerts_over_threshold(): void
    {
        config([
            'queue.default' => 'database',
            'platform.alerts.max_queue_depth' => 2,
            'platform.alerts.max_queue_age_seconds' => 60,
        ]);
        $this->seedJobs('heavy', 5, 300); // 5 jobs, 300s old

        $stats = collect(PlatformMetrics::queueStats())->firstWhere('queue', 'heavy');
        $this->assertSame(5, $stats['depth']);
        $this->assertGreaterThanOrEqual(300, $stats['oldest_age']);

        $alerts = PlatformMetrics::evaluateAlerts();
        $this->assertNotEmpty(array_filter($alerts, fn ($a) => str_contains($a, 'queue heavy backlog')));
        $this->assertNotEmpty(array_filter($alerts, fn ($a) => str_contains($a, 'oldest job')));
    }

    public function test_no_alert_under_threshold(): void
    {
        config([
            'queue.default' => 'database',
            'platform.alerts.max_queue_depth' => 500,
            'platform.alerts.max_queue_age_seconds' => 900,
        ]);
        $this->seedJobs('default', 2, 10);

        $alerts = PlatformMetrics::evaluateAlerts();
        $this->assertEmpty(array_filter($alerts, fn ($a) => str_contains($a, 'queue')));
    }

    public function test_healthz_reports_queue_block(): void
    {
        config(['queue.default' => 'database']);
        $this->seedJobs('default', 1, 5);

        $this->getJson('/healthz')
            ->assertOk()
            ->assertJsonPath('checks.queue.default.depth', 1);
    }
}
