<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use App\Services\PlatformMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * O6 — superadmin Failed Jobs console: list failed_jobs, retry, delete, flush.
 * Guarded to a database failed-job driver (a no-op page on `sync`).
 */
class FailedJobsAdminController extends Controller
{
    private function dbDriver(): bool
    {
        return str_starts_with((string) config('queue.failed.driver'), 'database');
    }

    public function index(Request $request)
    {
        $jobs = collect();
        $total = 0;
        if ($this->dbDriver()) {
            $total = DB::table('failed_jobs')->count();
            $jobs = DB::table('failed_jobs')->orderByDesc('failed_at')->paginate(30);
        }

        return view('admin.failed-jobs.index', [
            'jobs' => $jobs,
            'total' => $total,
            'driver' => (string) config('queue.failed.driver'),
            // H7: Queue Health card — depth, oldest job, worker/scheduler state.
            'queues' => PlatformMetrics::queueStats(),
            'workerAlive' => queue_worker_alive(),
            'schedulerAlive' => function_exists('scheduler_alive') ? scheduler_alive() : null,
            'schedulerAge' => function_exists('scheduler_last_run_age') ? scheduler_last_run_age() : null,
            'maxQueueDepth' => (int) config('platform.alerts.max_queue_depth', 500),
            'maxQueueAge' => (int) config('platform.alerts.max_queue_age_seconds', 900),
            'queueDriver' => (string) config('queue.default'),
        ]);
    }

    /** H7: delete cached scheduler mutexes so a self-skipping drain resumes next minute. */
    public function clearScheduleLocks(Request $request): RedirectResponse
    {
        Artisan::call('schedule:clear-cache');

        return back()->with('success', ___('Scheduler locks released.'));
    }

    public function retry(Request $request, string $uuid)
    {
        Artisan::call('queue:retry', ['id' => $uuid]);

        return back()->with('success', ___('Job re-queued.'));
    }

    public function forget(Request $request, string $uuid)
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return back()->with('success', ___('Job deleted.'));
    }

    public function flush(Request $request)
    {
        Artisan::call('queue:flush');

        return back()->with('success', ___('Failed jobs cleared.'));
    }
}
