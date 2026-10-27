<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Queue-worker + scheduler heartbeat signals (extracted from Helper.php to
 * hold the ControllerSizeGuardTest ceiling — same global-function contract).
 *
 * The worker publishes a heartbeat via Queue::looping (AppServiceProvider),
 * throttled there to once a minute.
 */

declare(strict_types=1);

/**
 * Whether a queue worker daemon is actively draining jobs.
 *
 * The worker publishes a heartbeat via Queue::looping (AppServiceProvider).
 * Sync queues count as "alive" (dispatch already runs inline there).
 */
function queue_worker_alive(): bool
{
    if (config('queue.default') === 'sync') {
        return true;
    }

    return (int) Cache::get('queue_worker_alive', 0) > time() - 180;
}

/**
 * Stamp the worker heartbeats for the current queue worker process. Called by
 * the Queue::looping listener (AppServiceProvider), throttled there to once a
 * minute. Kept argv-parameterised so the gate contract is testable.
 *
 * Any worker — including the scheduler's own fallback drains — stamps
 * `queue_worker_alive`, because web requests only need to know "will a queued
 * job be picked up soon" (dispatch_job_reliably). Only a DEDICATED daemon
 * (queue:work WITHOUT --stop-when-empty, i.e. deploy/supervisor/
 * juwri-worker.conf) also stamps `queue_worker_daemon_alive`, the key the
 * fallback drains gate on. A fallback drain must never stamp the daemon key:
 * its own heartbeat would keep the OTHER drain's gate closed for the full 180s
 * TTL — the 2026-09-01 prod incident where the default drain's stamp starved
 * the heavy drain and a TranslateMenuJob sat attempts=0 for 5h.
 *
 * @param  array<int, string>|null  $argv
 */
function queue_worker_stamp_heartbeat(?array $argv = null): void
{
    Cache::put('queue_worker_alive', time(), 180);

    $argv ??= (array) ($_SERVER['argv'] ?? []);
    // P6-134: a drain run INSIDE `schedule:run` (App\Console\InProcessSchedule, hosts without proc_open) has the argv of
    // `schedule:run`, not of `queue:work --stop-when-empty`, so the scheduler marks it with this container flag. Without it
    // the first drain of the minute stamped the daemon key and closed the gate of every later drain (heavy jobs never ran).
    $fallbackDrain = in_array('--stop-when-empty', $argv, true) || (function_exists('app') && app()->bound('queue.fallback_drain'));
    if (! $fallbackDrain) {
        Cache::put('queue_worker_daemon_alive', time(), 180);
    }
}

/**
 * Whether a DEDICATED queue worker daemon (deploy/supervisor/
 * juwri-worker.conf — queue:work without --stop-when-empty) is running.
 *
 * Gate signal for the scheduler's fallback drains only (Console\Kernel).
 * Deliberately NOT queue_worker_alive(): that key is stamped by every worker
 * including the fallback drains themselves, so gating on it let the default
 * drain's heartbeat permanently starve the heavy drain (2026-09-01).
 */
function queue_daemon_alive(): bool
{
    if (config('queue.default') === 'sync') {
        return true;
    }

    return (int) Cache::get('queue_worker_daemon_alive', 0) > time() - 180;
}

/**
 * O5 + P6-174: the scheduler heartbeat (Console\Kernel, every minute). Besides `schedule_last_run` it records WHO ran
 * the scheduler: the CLI cron (`schedule_cli_last_run`, the only run that reaches module commands, which are registered
 * for the CLI only) or the web cron /cronjob (`schedule_web_last_run`, plus whether it ran without proc_open, i.e. in
 * process via App\Console\InProcessSchedule, where module commands are skipped).
 */
function scheduler_stamp_heartbeat(?bool $console = null, ?bool $processes = null): void
{
    $now = time();
    Cache::put('schedule_last_run', $now, 900);
    if ($console ?? app()->runningInConsole()) {
        Cache::put('schedule_cli_last_run', $now, 7 * 86400);
    } else {
        Cache::put('schedule_web_last_run', ['at' => $now, 'in_process' => ! ($processes ?? function_exists('proc_open'))], 900);
    }
}

/** P6-174: seconds since the CLI cron last ran the scheduler, or null when it never has (in the last 7 days). */
function scheduler_cli_last_run_age(): ?int
{
    $last = (int) Cache::get('schedule_cli_last_run', 0);

    return $last > 0 ? time() - $last : null;
}

/**
 * P6-174: only the web cron runs the scheduler, and it runs in process (no proc_open), so module tasks and big backups
 * are skipped: the heartbeat is fresh and came from /cronjob, but the CLI cron has not run in the last 5 minutes.
 */
function scheduler_web_cron_only(): bool
{
    $web = Cache::get('schedule_web_last_run');
    $cli = scheduler_cli_last_run_age();

    return scheduler_alive()
        && is_array($web) && ($web['in_process'] ?? false) && (int) ($web['at'] ?? 0) > time() - 300
        && ($cli === null || $cli > 300);
}
