<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Http\Controllers\Admin\FailedJobsAdminController;
use App\Services\PlatformMetrics;
use Juwri\Core\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * O6 — failed-jobs visibility (admin console), prune schedule, and backlog alert.
 */
class FailedJobsTest extends TestCase
{
    use RefreshDatabase;

    private function seedFailed(int $n): array
    {
        $uuids = [];
        for ($i = 0; $i < $n; $i++) {
            $uuid = (string) Str::uuid();
            $uuids[] = $uuid;
            DB::table('failed_jobs')->insert([
                'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
                'payload' => '{}', 'exception' => "RuntimeException: boom\n#0 ...",
                'failed_at' => now(),
            ]);
        }

        return $uuids;
    }

    public function test_index_controller_lists_failed_jobs(): void
    {
        $this->seedFailed(2);

        $data = new FailedJobsAdminController()->index(new \Illuminate\Http\Request())->getData();
        $this->assertSame(2, $data['total']);
        $this->assertCount(2, $data['jobs']);
    }

    public function test_forget_and_flush_remove_rows(): void
    {
        [$uuid] = $this->seedFailed(3);
        $admin = User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);

        $this->actingAs($admin)->post(route('admin.failed-jobs.forget', $uuid))->assertRedirect();
        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $uuid)->count());

        $this->actingAs($admin)->post(route('admin.failed-jobs.flush'))->assertRedirect();
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_alert_over_threshold(): void
    {
        config(['platform.alerts.max_failed_jobs' => 5]);
        $this->seedFailed(6);

        $alerts = PlatformMetrics::evaluateAlerts();
        $this->assertNotEmpty(array_filter($alerts, fn ($a) => str_contains($a, 'failed jobs: 6')));
    }

    public function test_no_alert_under_threshold(): void
    {
        config(['platform.alerts.max_failed_jobs' => 25]);
        $this->seedFailed(2);

        $alerts = PlatformMetrics::evaluateAlerts();
        $this->assertEmpty(array_filter($alerts, fn ($a) => str_contains($a, 'failed jobs')));
    }

    public function test_prune_scheduled(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $kernel = $this->app->make(\App\Console\Kernel::class);
        $m = new \ReflectionMethod($kernel, 'schedule');
        $m->setAccessible(true);
        $m->invoke($kernel, $schedule);

        $found = collect($schedule->events())->contains(
            fn ($e) => is_string($e->command) && str_contains($e->command, 'queue:prune-failed')
        );
        $this->assertTrue($found, 'queue:prune-failed must be scheduled');
    }
}
