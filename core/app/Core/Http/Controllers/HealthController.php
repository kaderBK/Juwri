<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers;

use App\Services\PlatformMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O1 — liveness/readiness probe for external uptime monitors.
 *
 * Registered as GET /healthz at the top of routes/web.php, outside every
 * installed/auth group and before the {slug} catch-all. DB is the only HARD
 * check (its failure flips the endpoint to 503 so a monitor pages); cache and
 * storage are surfaced but soft; the scheduler and queue-worker heartbeats are
 * informational. H7: a *sustained* queue backlog (oldest job past the age
 * threshold) can also fail the probe, but only when the operator opts in via
 * settings.healthz_queue_backlog_fails (default off — see check()).
 */
class HealthController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $checks = [];

        // Hard check: database connectivity.
        $dbOk = true;
        try {
            DB::connection()->getPdo();
            DB::connection()->select('select 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $dbOk = false;
            $checks['database'] = 'down';
        }

        // Soft check: cache round-trip.
        try {
            $key = 'healthz_'.Str::random(8);
            Cache::put($key, '1', 5);
            $checks['cache'] = Cache::get($key) === '1' ? 'ok' : 'degraded';
            Cache::forget($key);
        } catch (\Throwable $e) {
            $checks['cache'] = 'degraded';
        }

        // Soft check: repo-root storage/ writability (the media dir, NOT core/storage).
        try {
            $checks['storage'] = is_writable(base_path('../storage')) ? 'ok' : 'degraded';
        } catch (\Throwable $e) {
            $checks['storage'] = 'degraded';
        }

        // Informational: queue worker heartbeat (never fails the probe).
        try {
            $checks['queue_worker'] = queue_worker_alive() ? 'ok' : 'idle';
        } catch (\Throwable $e) {
            $checks['queue_worker'] = 'unknown';
        }

        // Informational: scheduler heartbeat (O5 wires scheduler_alive()).
        if (function_exists('scheduler_alive')) {
            try {
                $checks['scheduler'] = scheduler_alive() ? 'ok' : 'degraded';
            } catch (\Throwable $e) {
                $checks['scheduler'] = 'unknown';
            }
        }

        // Informational: queue backlog per queue (O8; database driver only).
        // H7: `stalled` (oldest job older than the age threshold) is the *sustained*
        // signal — depth alone spikes during a normal import burst.
        $queueStalled = false;
        try {
            $maxDepth = (int) config('platform.alerts.max_queue_depth', 500);
            $maxAge = (int) config('platform.alerts.max_queue_age_seconds', 900);
            $queues = [];
            foreach (PlatformMetrics::queueStats() as $q) {
                $stalled = $q['oldest_age'] !== null && $q['oldest_age'] > $maxAge;
                $degraded = $q['depth'] > $maxDepth || $stalled;
                $queueStalled = $queueStalled || $stalled;
                $queues[$q['queue']] = [
                    'depth' => $q['depth'],
                    'oldest_age' => $q['oldest_age'],
                    'status' => $degraded ? 'degraded' : 'ok',
                ];
            }
            if ($queues) {
                $checks['queue'] = $queues;
            }
        } catch (\Throwable $e) {
            // informational only — never fail the probe
        }

        $checks['queue_backlog'] = $queueStalled ? 'stalled' : 'ok';

        // H7: a sustained backlog means the drain is not running. Default OFF: many
        // deployments wire /healthz to a load-balancer health check, where a 503 would
        // pull the web node out of rotation and escalate a queue backlog into a full
        // outage. The owner flips settings.healthz_queue_backlog_fails on once their
        // monitor is known to page a human rather than reroute traffic.
        $healthy = $dbOk && ! ($queueStalled && (bool) config('settings.healthz_queue_backlog_fails', false));

        // Detailed readiness data is public only outside production when no
        // token is configured. Production always requires an explicit matching
        // token for DB/cache/storage/queue/version details; bare status remains
        // available to simple liveness pings.
        $token = (string) config('app.healthz_token');
        $showDetail = $token !== ''
            ? hash_equals($token, (string) $request->query('token'))
            : ! app()->environment('production');

        $payload = ['status' => $healthy ? 'ok' : 'error'];
        if ($showDetail) {
            $payload['checks'] = $checks;
            $payload['version'] = config('appinfo.version');
        }

        return response()->json($payload, $healthy ? 200 : 503);
    }
}
