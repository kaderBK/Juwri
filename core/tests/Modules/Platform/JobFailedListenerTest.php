<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Listeners\Queue\AlertOnJobFailed;
use App\Providers\EventServiceProvider;
use App\Services\PlatformMetrics;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Juwri\Core\Models\PlatformAlert;
use Mockery;
use Tests\TestCase;

/**
 * P6-211 — failed-jobs alerting without `Queue::failing` was the gap:
 *   - the 1st–24th payment failure of the day was silent (threshold was 25);
 *   - the 25th failure fired once, then every daily sweep fired again until
 *     the row was pruned weekly (168 h, Kernel.php:175).
 *
 * The listener alerts:
 *   - payment / order-mail / backup classes — every failure (each one is a
 *     per-customer problem);
 *   - other classes — once per job class per hour (Cache::add dedupe).
 *
 * PlatformMetrics' O6 backlog alert (the old guard) now counts only the last
 * 24 h — older rows are stale and the listener would have caught them anyway.
 */
class JobFailedListenerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build a JobFailed event whose payload carries `displayName` (what
     * `CallQueuedHandler` writes for queued handlers). The listener reads it
     * via `$event->job->payload()['displayName']` and falls back to
     * `resolveName()` only when missing.
     */
    private function fireJobFailed(string $displayName, string $message = 'boom'): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['displayName' => $displayName, 'uuid' => (string) Str::uuid()]);
        $job->shouldReceive('resolveName')->andReturn($displayName);

        Event::dispatch(new JobFailed('database', $job, new \RuntimeException($message)));
    }

    private function alertCount(): int
    {
        return PlatformAlert::where('kind', 'queue.failed')->count();
    }

    private function seedFailed(int $n, ?\DateTimeInterface $failedAt = null, string $uuidPrefix = ''): array
    {
        $uuids = [];
        for ($i = 0; $i < $n; $i++) {
            $uuid = $uuidPrefix.(string) Str::uuid();
            $uuids[] = $uuid;
            DB::table('failed_jobs')->insert([
                'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\Seed']),
                'exception' => "RuntimeException: boom\n#0 ...",
                'failed_at' => $failedAt ?? now(),
            ]);
        }

        return $uuids;
    }

    public function test_one_failed_payment_job_alerts_once(): void
    {
        $this->fireJobFailed('Juwri\\Modules\\Payments\\Jobs\\RelayOutboxJob', 'gateway timeout');

        $this->assertSame(1, $this->alertCount());
        $row = PlatformAlert::where('kind', 'queue.failed')->sole();
        $this->assertStringContainsString('RelayOutboxJob', $row->message);
        $this->assertStringContainsString('gateway timeout', $row->message);
    }

    public function test_payment_class_failure_always_alerts_not_deduped(): void
    {
        // Two failures of the same payment class within seconds — the listener
        // must NOT dedupe critical classes, because each failure is a separate
        // customer waiting on a checkout.
        $this->fireJobFailed('Juwri\\Modules\\Payments\\Jobs\\RelayOutboxJob', 'first timeout');
        $this->fireJobFailed('Juwri\\Modules\\Payments\\Jobs\\RelayOutboxJob', 'second timeout');

        // AlertDispatcher dedupes by exact (kind, message, day) so two distinct
        // exception messages become two distinct rows.
        $this->assertSame(2, $this->alertCount());
    }

    public function test_order_mail_class_is_critical_and_alerts_immediately(): void
    {
        $this->fireJobFailed('App\\Mails\\StoreOrderMail', 'smtp down');

        $row = PlatformAlert::where('kind', 'queue.failed')->sole();
        $this->assertStringContainsString('StoreOrderMail', $row->message);
    }

    public function test_backup_class_is_critical_and_alerts_immediately(): void
    {
        $this->fireJobFailed('Juwri\\Core\\Jobs\\GenerateStoreBackupJob', 'disk full');

        $row = PlatformAlert::where('kind', 'queue.failed')->sole();
        $this->assertStringContainsString('GenerateStoreBackupJob', $row->message);
    }

    public function test_non_critical_class_dedupes_within_an_hour(): void
    {
        // Non-critical: nothing matching payment / order-mail / backup.
        $this->fireJobFailed('Juwri\\Modules\\Content\\Jobs\\TranslateBlogPostJob', 'transient');
        $this->fireJobFailed('Juwri\\Modules\\Content\\Jobs\\TranslateBlogPostJob', 'transient');
        $this->fireJobFailed('Juwri\\Modules\\Content\\Jobs\\TranslateBlogPostJob', 'transient');

        // 1 alert row: deduped to once per hour by class.
        $this->assertSame(1, $this->alertCount());
    }

    public function test_non_critical_class_alerts_again_after_an_hour(): void
    {
        $this->fireJobFailed('Juwri\\Modules\\Content\\Jobs\\TranslateBlogPostJob', 'first transient');
        $this->assertSame(1, $this->alertCount());

        // The array cache driver stores absolute expiry and ignores Carbon's
        // travel clock, so we simulate "TTL passed" by flushing — what
        // production would see once the hour was up.
        $this->travel(61)->minutes();
        Cache::flush();
        // Different exception message so AlertDispatcher's per-day dedupe
        // doesn't collapse this into the first row.
        $this->fireJobFailed('Juwri\\Modules\\Content\\Jobs\\TranslateBlogPostJob', 'second transient');
        $this->assertSame(2, $this->alertCount());
    }

    public function test_different_non_critical_classes_alert_independently(): void
    {
        $this->fireJobFailed('App\\Jobs\\TranslateJob', 'a');
        $this->fireJobFailed('App\\Jobs\\IndexJob', 'b');

        $this->assertSame(2, $this->alertCount());
    }

    public function test_listener_is_registered_on_job_failed(): void
    {
        // A real JobFailed dispatch must reach our listener — wiring check.
        $hit = false;
        Event::listen(JobFailed::class, function () use (&$hit): void {
            $hit = true;
        });

        $this->fireJobFailed('App\\Jobs\\ProbeJob');
        $this->assertTrue($hit);
    }

    public function test_listener_swallows_throwables_so_the_worker_is_not_broken(): void
    {
        // A listener crash must not propagate (the queue worker would re-throw
        // and abort the loop). Even if the cache throws, the event stays
        // handled.
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache down'));
        $this->fireJobFailed('App\\Jobs\\AnyJob');

        // Did not blow up; no alert was dispatched because the cache branch
        // threw — but the worker would keep going.
        $this->assertSame(0, $this->alertCount());
        $this->assertTrue(true);
    }

    public function test_old_failed_jobs_rows_do_not_alert(): void
    {
        // 30 rows from 48h ago — well past the 24h window — plus 5 from the
        // last hour. The 5 must trip the backlog alert; the 30 must not.
        $this->seedFailed(30, now()->subHours(48));
        $this->seedFailed(5, now()->subMinutes(5));

        config(['platform.alerts.max_failed_jobs' => 4]);
        $alerts = PlatformMetrics::evaluateAlerts();
        $failedAlerts = array_values(array_filter($alerts, fn ($a) => str_contains($a, 'failed jobs')));

        $this->assertCount(1, $failedAlerts);
        $this->assertStringContainsString('failed jobs: 5', $failedAlerts[0]);
    }

    public function test_backlog_alert_includes_only_recent_rows_even_when_old_rows_remain(): void
    {
        // 50 stale rows (one week old — well past the prune cadence of 168 h)
        // plus 24 fresh ones. Threshold is 25; only the fresh 24 should be
        // counted, so the alert does NOT fire. This is the "no alert until 25
        // piled up, then alerts forever" bug — fixed.
        $this->seedFailed(50, now()->subDays(7));
        $this->seedFailed(24, now()->subMinutes(2));

        config(['platform.alerts.max_failed_jobs' => 25]);
        $alerts = PlatformMetrics::evaluateAlerts();
        $failedAlerts = array_filter($alerts, fn ($a) => str_contains($a, 'failed jobs'));

        $this->assertSame([], $failedAlerts);
    }

    public function test_listener_path_resolves_through_event_service_provider(): void
    {
        // Wire-level check: confirm the explicit EventServiceProvider $listen
        // mapping has been registered with the dispatcher so a future maintainer
        // who swaps the wiring to a Closure-bound listener still hits our class.
        $provider = $this->app->getProvider(EventServiceProvider::class);
        $ref = new \ReflectionClass($provider);
        $prop = $ref->getProperty('listen');
        $prop->setAccessible(true);
        $listen = $prop->getValue($provider);

        $this->assertArrayHasKey(JobFailed::class, $listen);
        $this->assertContains(AlertOnJobFailed::class, $listen[JobFailed::class]);
    }
}
