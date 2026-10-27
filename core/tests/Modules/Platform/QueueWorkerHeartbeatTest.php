<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * P10 — heartbeat contract for queue workers, and the fallback-drain gate.
 *
 * Two signals, two audiences (split after the 2026-09-01 prod incident):
 * - `queue_worker_alive` — stamped by ANY worker (daemon or fallback drain).
 *   Consumed by web requests (dispatch_job_reliably) that only need to know
 *   "will a queued job be picked up soon".
 * - `queue_worker_daemon_alive` — stamped ONLY by a dedicated daemon
 *   (queue:work WITHOUT --stop-when-empty, i.e. deploy/supervisor/
 *   juwri-worker.conf). Consumed ONLY by the scheduler fallback drains'
 *   ->when(fn () => ! queue_daemon_alive()) gate.
 *
 * The incident: both drains used to gate on queue_worker_alive, which the
 * default fallback drain itself stamps on startup (even on an empty queue).
 * Evaluated in order, the default drain's stamp then kept the heavy drain's
 * gate closed for the full 180s TTL — forever, in practice — so every `heavy`
 * job (store backups, AI menu translations) sat attempts=0 until drained by
 * hand. These tests pin that a fallback drain can NEVER close the gate.
 */
class QueueWorkerHeartbeatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']); // not sync, so the heartbeats matter
        Cache::forget('queue_worker_alive');
        Cache::forget('queue_worker_daemon_alive');
    }

    /** Web-request signal: fresh = "a worker will pick jobs up soon". */
    public function test_worker_alive_signal_semantics(): void
    {
        $this->assertFalse(queue_worker_alive(), 'no heartbeat → nothing will drain jobs');

        Cache::put('queue_worker_alive', time(), 180);
        $this->assertTrue(queue_worker_alive(), 'fresh heartbeat → a worker is draining');

        Cache::put('queue_worker_alive', time() - 300, 600);
        $this->assertFalse(queue_worker_alive(), 'stale heartbeat → treated as dead');
    }

    /** Daemon signal: fresh = the supervisor daemon is running → drains self-skip. */
    public function test_daemon_alive_signal_semantics(): void
    {
        $this->assertFalse(queue_daemon_alive(), 'no daemon heartbeat → fallback drains run');

        Cache::put('queue_worker_daemon_alive', time(), 180);
        $this->assertTrue(queue_daemon_alive(), 'fresh daemon heartbeat → drains self-skip');

        Cache::put('queue_worker_daemon_alive', time() - 300, 600);
        $this->assertFalse(queue_daemon_alive(), 'stale daemon heartbeat → drains resume');
    }

    /** P6-134: a drain inside schedule:run has the argv of schedule:run; the scheduler's container flag says it is a fallback drain. */
    public function test_in_process_drain_flag_keeps_the_gate_open_without_the_argv_marker(): void
    {
        queue_worker_stamp_heartbeat(['artisan', 'schedule:run']);
        $this->assertTrue(queue_daemon_alive(), 'without the flag a bare argv looks like a dedicated daemon');

        Cache::forget('queue_worker_daemon_alive');
        $this->app->instance('queue.fallback_drain', true);
        queue_worker_stamp_heartbeat(['artisan', 'schedule:run']);
        $this->app->forgetInstance('queue.fallback_drain');

        $this->assertTrue(queue_worker_alive());
        $this->assertFalse(Cache::has('queue_worker_daemon_alive'), 'a drain inside schedule:run must not stamp the daemon key');
        $this->assertFalse(queue_daemon_alive());
    }

    /**
     * The regression: a fallback drain stamps the web signal but must NOT
     * stamp the daemon key — its own stamp is what starved the heavy drain.
     */
    public function test_fallback_drain_stamp_does_not_close_the_gate(): void
    {
        queue_worker_stamp_heartbeat(
            ['artisan', 'queue:work', '--stop-when-empty', '--max-time=50']
        );

        $this->assertTrue(queue_worker_alive(), 'fallback drain still answers web requests');
        $this->assertFalse(Cache::has('queue_worker_daemon_alive'), 'fallback must not stamp the daemon key');
        $this->assertFalse(queue_daemon_alive(), 'gate stays open → the heavy drain can run');

        queue_worker_stamp_heartbeat(
            ['artisan', 'queue:work', '--queue=heavy', '--stop-when-empty', '--max-time=1800']
        );

        $this->assertTrue(queue_worker_alive());
        $this->assertFalse(Cache::has('queue_worker_daemon_alive'), 'heavy fallback must not stamp it either');
        $this->assertFalse(queue_daemon_alive());
    }

    /** A dedicated daemon (no --stop-when-empty) stamps BOTH keys. */
    public function test_dedicated_daemon_stamp_closes_the_gate(): void
    {
        queue_worker_stamp_heartbeat(
            ['artisan', 'queue:work', '--queue=heavy,default', '--sleep=1', '--tries=3']
        );

        $this->assertTrue(queue_worker_alive());
        $this->assertTrue(queue_daemon_alive(), 'daemon stamp must close the fallback-drain gate');
    }

    /**
     * End-to-end on the actual schedule: with only the WEB signal fresh (a
     * fallback drain just ran, as in the incident), BOTH drains' filters must
     * still pass; with the DAEMON signal fresh, both must skip.
     */
    public function test_drain_gates_consult_the_daemon_key_not_the_worker_key(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $kernel = $this->app->make(Kernel::class);
        $method = new \ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        // Resolving Schedule at boot may already have registered the drains once,
        // so the reflection invoke can duplicate them — dedupe by command string.
        $drains = collect($schedule->events())
            ->filter(fn ($e) => is_string($e->command) && str_contains($e->command, 'queue:work'))
            ->unique('command')->values();
        $this->assertCount(2, $drains->all(), 'expected the default + heavy fallback drains');

        // The incident state: default drain ran, stamped the web key only.
        Cache::put('queue_worker_alive', time(), 180);
        Cache::forget('queue_worker_daemon_alive');
        $drains->each(
            fn ($e) => $this->assertTrue(
                $e->filtersPass($this->app),
                "drain [{$e->command}] must not be starved by a fallback drain's heartbeat"
            )
        );

        // The intended skip state: a dedicated daemon is running.
        Cache::put('queue_worker_daemon_alive', time(), 180);
        $drains->each(
            fn ($e) => $this->assertFalse(
                $e->filtersPass($this->app),
                "drain [{$e->command}] must self-skip while a dedicated daemon runs"
            )
        );
    }

    /**
     * Long-running jobs stamp the heartbeats from inside handle() because
     * Queue::looping does not fire mid-job. They must go through the shared
     * helper — a direct `queue_worker_alive` stamp inside a daemon would let
     * the daemon key go stale mid-job and reopen the fallback-drain race.
     */
    public function test_long_running_jobs_stamp_via_the_shared_helper(): void
    {
        foreach (['Modules/Menu/Jobs/TranslateMenuJob', 'Modules/Menu/Jobs/GenerateMenuItemImagesJob', 'Modules/Ai/Jobs/LangChromeTranslateJob', 'Modules/Biolink/Jobs/TranslateBioBlocksJob'] as $job) {
            $path = str_contains($job, '/') ? "app/{$job}.php" : "app/Jobs/{$job}.php";
            $src = (string) file_get_contents(base_path($path));
            $this->assertStringContainsString('queue_worker_stamp_heartbeat()', $src, "{$job} must stamp via the shared helper");
            $this->assertStringNotContainsString("Cache::put('queue_worker_alive'", $src, "{$job} must not stamp the web key directly");
        }
    }

    public function test_supervisor_program_is_shipped_and_documented(): void
    {
        $conf = base_path('../deploy/supervisor/juwri-worker.conf');
        $this->assertFileExists($conf);
        $this->assertStringContainsString('queue:work --queue=default', file_get_contents($conf));
        $this->assertStringContainsString('queue:work heavy --queue=heavy', file_get_contents($conf)); // P6-64b

        $checklist = file_get_contents(base_path('../deploy/PROD-HARDENING-CHECKLIST.md'));
        $this->assertStringContainsString('juwri-worker.conf', $checklist);
    }
}
