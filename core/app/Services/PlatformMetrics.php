<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Juwri\Core\Console\Commands\PlatformDbBackup;
use Juwri\Core\Models\AbuseEvent;
use Juwri\Core\Models\PlatformBackupRun;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Services\Ops\DatabaseDump;
use Juwri\Core\Services\Ops\EnvFileHealth;
use Juwri\Modules\Ai\Models\AiUsage;

/**
 * P5-3: per-render + per-app metrics on daily DB counters. Recording is
 * fire-and-forget (a metrics failure must never break a storefront request).
 * Alert evaluation runs on the daily schedule.
 */
class PlatformMetrics
{
    /** Atomically bump a (day, metric, label) counter, optionally adding latency. */
    public static function record(string $metric, ?string $label = null, int $ms = 0): void
    {
        try {
            $wrap = fn (string $c): string => DB::connection()->getQueryGrammar()->wrap($c);
            DB::table('platform_metrics')->upsert(
                [[
                    'day' => now()->toDateString(),
                    'metric' => $metric,
                    'label' => $label !== null ? substr($label, 0, 120) : null,
                    'count' => 1,
                    'sum_ms' => max(0, $ms),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['day', 'metric', 'label'],
                [
                    // qualified: on PostgreSQL a bare "count" in ON CONFLICT DO UPDATE is ambiguous (the error was
                    // swallowed below but aborted the surrounding transaction).
                    'count' => DB::raw($wrap('platform_metrics.count').' + 1'),
                    'sum_ms' => DB::raw($wrap('platform_metrics.sum_ms').' + '.max(0, $ms)),
                    'updated_at' => now(),
                ],
            );
        } catch (\Throwable $e) {
            report($e); // never break the caller
        }
    }

    /** Today's count for a metric (all labels). */
    public static function todayCount(string $metric): int
    {
        return (int) DB::table('platform_metrics')
            ->where('day', now()->toDateString())
            ->where('metric', $metric)
            ->sum('count');
    }

    /**
     * Evaluate alert thresholds over TODAY's counters. Returns the alerts it
     * raised (also logged as errors — the monitoring hook on cron-only hosts).
     *
     * @return list<string>
     */
    public static function evaluateAlerts(): array
    {
        $alerts = [];

        $renderFails = static::todayCount('render.fail');
        $renderOk = static::todayCount('render.ok');
        $total = $renderFails + $renderOk;
        $maxRate = (float) config('platform.alerts.max_render_failure_rate', 0.05);
        if ($total >= 20 && $renderFails / $total > $maxRate) {
            $alerts[] = sprintf('render failure rate %.1f%% (%d/%d) exceeds %.0f%%', 100 * $renderFails / $total, $renderFails, $total, 100 * $maxRate);
        }

        $authFails = static::todayCount('app.auth_fail');
        $maxAuthFails = (int) config('platform.alerts.max_app_auth_fails', 100);
        if ($authFails > $maxAuthFails) {
            $alerts[] = "app session-token auth failures today: {$authFails} (abuse signal, threshold {$maxAuthFails})";
        }

        $paymentFails = static::todayCount('app.payment_fail');
        $maxPaymentFails = (int) config('platform.alerts.max_app_payment_fails', 50);
        if ($paymentFails > $maxPaymentFails) {
            $alerts[] = "app payment failures today: {$paymentFails} (threshold {$maxPaymentFails})";
        }

        // H6: order creations rolling back (a transaction failure on the checkout path).
        $orderFails = static::todayCount('order.create_failed');
        $maxOrderFails = (int) config('platform.alerts.max_order_create_fails', 10);
        if ($orderFails > $maxOrderFails) {
            $alerts[] = "order creations rolled back today: {$orderFails} (threshold {$maxOrderFails})";
        }

        foreach (static::abuseAlerts() as $a) {
            $alerts[] = $a;
        }

        // O7: stale platform-backup alert (only meaningful where the backup
        // command actually writes — mysql/mariadb/pgsql since bug 5; skip on sqlite/CI).
        if (DatabaseDump::supports(DB::connection()->getDriverName())) {
            $age = static::latestPlatformBackupAgeHours();
            $maxAge = (int) config('platform.alerts.max_backup_age_hours', 26);
            if ($age === null) {
                $alerts[] = 'no platform DB backup found (expected daily via platform:db-backup)';
            } elseif ($age > $maxAge) {
                $alerts[] = sprintf('platform DB backup is %dh old (threshold %dh)', $age, $maxAge);
            }

            /* C2: freshness is not validity. A truncated dump is "0h old"
               forever, so the staleness checks above structurally cannot see the
               failure these alerts exist for. Kept as its own helper so it is
               unit-testable on sqlite — but only ever CALLED inside this server-driver
               block, where the backup actually writes. */
            foreach (static::backupValidityAlerts() as $a) {
                $alerts[] = $a;
            }
        }

        // O8: queue backlog — depth + oldest-job age per queue (database driver only).
        // H7: extracted into queueAlerts() so the scheduler can evaluate it hourly.
        foreach (static::queueAlerts() as $a) {
            $alerts[] = $a;
        }

        // O6: failed-jobs backlog alert (guarded to a DB failed driver).
        // P6-211: count the LAST 24 h, not all rows — the queue:prune-failed
        // sweep is weekly (Kernel.php:175, 168 h), so the table can hold up to
        // seven days of dead jobs. The listener above catches new ones the moment
        // they fail; this alert exists for the case where the listener was off
        // for a stretch (cold cache, deploy, queue worker restart). Anything
        // older than the 24 h window is stale and must not contribute.
        try {
            if (str_starts_with((string) config('queue.failed.driver'), 'database')) {
                $failed = DB::table('failed_jobs')
                    ->where('failed_at', '>=', now()->subDay())
                    ->count();
                $maxFailed = (int) config('platform.alerts.max_failed_jobs', 25);
                if ($failed > $maxFailed) {
                    $alerts[] = "failed jobs: {$failed} (threshold {$maxFailed}) — see Admin → Failed Jobs";
                }
            }
        } catch (\Throwable $e) {
            // failed_jobs table may be absent on a half-migrated box; ignore
        }

        // H8: .env file health — a leaked APP_KEY, duplicate or unparseable key.
        // Gated on the admin_env_health_alert Option (default on) so an operator
        // who knowingly keeps an odd .env can silence the alert without losing the
        // System Info panel.
        if (config('settings.admin_env_health_alert', true)) {
            $env = EnvFileHealth::report();
            if ($env['readable'] && ! $env['ok']) {
                $n = count($env['issues']);
                $alerts[] = 'env file: '.$n.' issue(s) — run php artisan platform:env-health';
            }
        }

        // O4: fan out through the shared dispatcher (persists a dashboard row +
        // logs to the platform_alerts channel + optional Slack/email).
        foreach ($alerts as $alert) {
            AlertDispatcher::send('metrics', $alert);
        }

        return $alerts;
    }

    /**
     * H7: queue backlog alerts, extracted from evaluateAlerts() so the scheduler can
     * evaluate them hourly instead of once a day. Returns the alert strings; the
     * caller decides whether to dispatch. Pure read — safe to call from a probe.
     *
     * @return list<string>
     */
    public static function queueAlerts(): array
    {
        $alerts = [];
        $maxDepth = (int) config('platform.alerts.max_queue_depth', 500);
        $maxAge = (int) config('platform.alerts.max_queue_age_seconds', 900);
        foreach (static::queueStats() as $q) {
            if ($q['depth'] > $maxDepth) {
                $alerts[] = sprintf('queue %s backlog: %d jobs (threshold %d)', $q['queue'], $q['depth'], $maxDepth);
            }
            if ($q['oldest_age'] !== null && $q['oldest_age'] > $maxAge) {
                $alerts[] = sprintf('queue %s oldest job %ds old (threshold %ds)', $q['queue'], $q['oldest_age'], $maxAge);
            }
        }

        return $alerts;
    }

    /**
     * O8: per-queue depth + oldest-job age from the `jobs` table (database queue
     * driver only; empty otherwise). Also surfaced on /healthz. Uses the query
     * builder so the qrtest_ prefix is applied — never a hardcoded table name.
     *
     * @return list<array{queue:string,depth:int,oldest_age:int|null}>
     */
    public static function queueStats(): array
    {
        if (config('queue.default') !== 'database') {
            return [];
        }
        try {
            return DB::table('jobs')
                ->selectRaw('queue, count(*) as depth, min(available_at) as oldest')
                ->groupBy('queue')
                ->get()
                ->map(fn ($r) => [
                    'queue' => (string) $r->queue,
                    'depth' => (int) $r->depth,
                    'oldest_age' => $r->oldest !== null ? max(0, time() - (int) $r->oldest) : null,
                ])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * C2: validity (not freshness) alerts for the platform backup + restore
     * drill. Extracted so it is unit-testable on sqlite; evaluateAlerts() calls
     * it only inside the mysql guard, where the backup command actually runs.
     *
     * @return list<string>
     */
    public static function backupValidityAlerts(): array
    {
        $alerts = [];

        $lastBackup = PlatformBackupRun::latestOf('backup');
        if ($lastBackup && ! $lastBackup->ok) {
            $alerts[] = 'last platform DB backup was REJECTED: '.($lastBackup->error ?: 'unknown reason');
        }

        if (PlatformBackupRun::restoreCheckEnabled()) {
            $maxDrillDays = (int) config('platform.alerts.max_restore_check_age_days', 8);
            $drill = PlatformBackupRun::latestOf('restore_check');
            if (! $drill) {
                $alerts[] = 'no platform DB restore drill has ever run';
            } elseif (! $drill->ok) {
                $alerts[] = 'last platform DB restore drill FAILED: '.($drill->error ?: 'unknown reason');
            } elseif ($drill->created_at && $drill->created_at->lt(now()->subDays($maxDrillDays))) {
                $alerts[] = sprintf('no successful platform DB restore drill in %d days', $maxDrillDays);
            }
        }

        return $alerts;
    }

    /**
     * Age in whole hours of the newest platform DB backup, or null when none
     * exists. Surfaced on the ops dashboard + fed to evaluateAlerts (O7).
     */
    public static function latestPlatformBackupAgeHours(): ?int
    {
        $dir = storage_path('app/'.PlatformDbBackup::DISK_DIR);
        if (! is_dir($dir)) {
            return null;
        }
        $newest = null;
        foreach (glob($dir.'/db-*.sql.gz') ?: [] as $file) {
            $mtime = filemtime($file);
            if ($newest === null || $mtime > $newest) {
                $newest = $mtime;
            }
        }

        return $newest === null ? null : (int) floor((time() - $newest) / 3600);
    }

    /**
     * Abuse Guard P6 (6.2): signup spike, AI platform-spend >80% of the daily cap,
     * and any single user flooding abuse_events. Thresholds are admin-editable
     * abuse_alert_* settings. Read-only signals — no enforcement here.
     */
    protected static function abuseAlerts(): array
    {
        $alerts = [];

        try {
            // Signup spike (abuse_events guard=signup in the trailing hour).
            $signupPerHour = (int) config('settings.abuse_alert_signups_per_hour', 30);
            $signups = AbuseEvent::where('guard', 'signup')
                ->where('created_at', '>=', now()->subHour())->count();
            if ($signupPerHour > 0 && $signups > $signupPerHour) {
                $alerts[] = "signup spike: {$signups} signup abuse events in the last hour (threshold {$signupPerHour})";
            }

            // AI platform spend > 80% of the daily cap.
            $platformCap = (int) config('settings.abuse_ai_spend_platform_daily_cap', 10000);
            if ($platformCap > 0) {
                $spend = AiUsage::where('created_at', '>=', now()->startOfDay())->count();
                if ($spend >= 0.8 * $platformCap) {
                    $pct = round(100 * $spend / $platformCap);
                    $alerts[] = "AI platform spend at {$pct}% of daily cap ({$spend}/{$platformCap})";
                }
            }

            // Heavy-tenant detection: any tenant taking an outsized share of the box.
            $reqPct = (float) config('settings.abuse_heavy_tenant_pct', 10);
            if ($reqPct > 0) {
                $totalReq = (int) TenantUsageDaily::whereDate('date', now()->toDateString())
                    ->whereIn('metric', ['requests_public', 'requests_dashboard', 'requests_api'])
                    ->sum('value');
                if ($totalReq > 0) {
                    $top = TenantUsageDaily::whereDate('date', now()->toDateString())
                        ->whereIn('metric', ['requests_public', 'requests_dashboard', 'requests_api'])
                        ->selectRaw('user_id, sum(value) as c')
                        ->groupBy('user_id')->orderByDesc('c')->first();
                    if ($top && 100 * $top->c / $totalReq > $reqPct) {
                        $pct = round(100 * $top->c / $totalReq);
                        $alerts[] = "heavy tenant: user {$top->user_id} = {$pct}% of platform requests today (threshold {$reqPct}%)";
                    }
                }
            }

            // Rate-of-change: a tenant whose request volume today is a large multiple
            // of its OWN trailing baseline. The share threshold above only fires once a
            // tenant is already a big fraction of the box; this catches a 40 → 8,000/day
            // surge on the day it happens (e.g. a runaway poll loop starting), well
            // before it crosses that share. Bounded to today's top few tenants.
            $surgeFactor = (float) config('settings.abuse_alert_surge_factor', 4);
            $surgeFloor = (int) config('settings.abuse_alert_surge_floor', 3000);
            if ($surgeFactor > 1 && $surgeFloor > 0) {
                $reqMetrics = ['requests_public', 'requests_dashboard', 'requests_api'];
                $todayTop = TenantUsageDaily::whereDate('date', now()->toDateString())
                    ->whereIn('metric', $reqMetrics)
                    ->selectRaw('user_id, sum(value) as c')
                    ->groupBy('user_id')->orderByDesc('c')->limit(5)->get();
                foreach ($todayTop as $row) {
                    // `c` is the selectRaw sum alias — read via getAttribute so the
                    // dynamic column doesn't trip PHPStan's undefined-property check.
                    $todayReq = (int) $row->getAttribute('c');
                    $uid = (int) $row->getAttribute('user_id');
                    if ($todayReq < $surgeFloor) {
                        continue; // below the noise floor — ignore
                    }
                    // Trailing 7-day baseline (excluding today), as a per-day average.
                    $priorSum = (int) TenantUsageDaily::where('user_id', $uid)
                        ->whereIn('metric', $reqMetrics)
                        ->whereBetween('date', [now()->subDays(7)->toDateString(), now()->subDay()->toDateString()])
                        ->sum('value');
                    $baselineDaily = $priorSum / 7;
                    if ($baselineDaily <= 0 || $todayReq / max($baselineDaily, 1) >= $surgeFactor) {
                        $desc = $baselineDaily <= 0
                            ? 'no prior activity (new)'
                            : '~'.round($baselineDaily).'/day baseline ('.round($todayReq / max($baselineDaily, 1)).'x)';
                        $alerts[] = "request surge: user {$uid} at {$todayReq} requests today vs {$desc}";
                    }
                }
            }

            $aiPct = (float) config('settings.abuse_heavy_tenant_ai_pct', 25);
            if ($aiPct > 0) {
                $totalAi = (int) AiUsage::where('created_at', '>=', now()->startOfDay())->count();
                if ($totalAi > 0) {
                    $topAi = AiUsage::where('created_at', '>=', now()->startOfDay())
                        ->whereNotNull('user_id')
                        ->selectRaw('user_id, count(*) as c')
                        ->groupBy('user_id')->orderByDesc('c')->first();
                    if ($topAi && 100 * $topAi->c / $totalAi > $aiPct) {
                        $pct = round(100 * $topAi->c / $totalAi);
                        $alerts[] = "heavy AI tenant: user {$topAi->user_id} = {$pct}% of AI spend today (threshold {$aiPct}%)";
                    }
                }
            }

            // Single-user abuse-event flood today.
            $userMax = (int) config('settings.abuse_alert_user_events_per_day', 50);
            if ($userMax > 0) {
                $top = AbuseEvent::whereNotNull('user_id')
                    ->where('created_at', '>=', now()->startOfDay())
                    ->selectRaw('user_id, count(*) as c')
                    ->groupBy('user_id')
                    ->orderByDesc('c')
                    ->first();
                if ($top && $top->c > $userMax) {
                    $alerts[] = "user {$top->user_id} triggered {$top->c} abuse events today (threshold {$userMax})";
                }
            }
        } catch (\Throwable $e) {
            // metrics must never break the caller
        }

        return $alerts;
    }
}
