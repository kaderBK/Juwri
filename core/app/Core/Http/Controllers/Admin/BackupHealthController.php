<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin;

use App\Services\PlatformMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Juwri\Core\Console\Commands\PlatformDbBackup;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\PlatformBackupRun;

/**
 * C2 — superadmin Backup Health console. Before this, backup state was
 * visible nowhere in the product: the only signal was a staleness alert that
 * a truncated-but-fresh dump satisfies.
 */
class BackupHealthController extends Controller
{
    public function index(Request $request): View
    {
        $dir = storage_path('app/'.PlatformDbBackup::DISK_DIR);
        $files = collect(glob($dir.'/db-*.sql.gz') ?: [])
            ->map(fn ($f) => ['name' => basename($f), 'bytes' => (int) @filesize($f), 'mtime' => (int) @filemtime($f)])
            ->sortByDesc('mtime')->values();

        return view('admin.backup-health.index', [
            'ageHours' => PlatformMetrics::latestPlatformBackupAgeHours(),
            'maxAgeHours' => (int) config('platform.alerts.max_backup_age_hours', 26),
            'lastBackup' => PlatformBackupRun::latestOf('backup'),
            'lastDrill' => PlatformBackupRun::latestOf('restore_check'),
            'drillEnabled' => PlatformBackupRun::restoreCheckEnabled(),
            'runs' => PlatformBackupRun::orderByDesc('id')->limit(30)->get(),
            'files' => $files,
            'driver' => DB::connection()->getDriverName(),
            // M7: off-box + media coverage.
            'lastMedia' => PlatformBackupRun::latestOf('media'),
            'mediaEnabled' => (bool) config('settings.platform_media_backup_enabled'),
            'offboxTarget' => (bool) config('filesystems.disks.s3.key') && (bool) config('filesystems.disks.s3.bucket')
                                ? (string) config('filesystems.disks.s3.bucket') : null,
            'mediaRoot' => (string) (config('backup.media_root') ?: public_path('storage')),
        ]);
    }

    public function runMediaMirror(Request $request): RedirectResponse
    {
        /* Queued on `heavy`: a first mirror moves the whole media tree (~800 MB
           on the reference box) and would time the request out. The heavy queue
           has a scheduler-cron fallback drain, so this completes even with no
           supervisor. --force bypasses the settings gate so the button works
           before the nightly schedule is turned on. */
        Artisan::queue('platform:media-backup', ['--force' => true])->onQueue('heavy');

        return back()->with('success', ___('Media mirror queued. Refresh in a few minutes for the result.'));
    }

    public function runRestoreCheck(Request $request): RedirectResponse
    {
        /* Queued, not inline: restoring a multi-GB dump takes minutes and would
           time the request out. The `heavy` queue already has a scheduler-cron
           fallback drain (Kernel.php:75-77), so this completes even on hosts
           with no supervisor. --force bypasses the settings gate so the button
           works before the operator turns the weekly schedule on. */
        Artisan::queue('platform:db-restore-check', ['--force' => true])->onQueue('heavy');

        return back()->with('success', ___('Restore drill queued. Refresh in a few minutes for the result.'));
    }
}
