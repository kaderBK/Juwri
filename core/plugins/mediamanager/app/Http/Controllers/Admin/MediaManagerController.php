<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Plugins\mediamanager\app\Http\Controllers\Admin;

use App\Models\Option;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Http\Controllers\Controller;
use Plugins\mediamanager\app\Models\MediaCleanupLog;
use Plugins\mediamanager\app\Models\MediaScanFile;
use Plugins\mediamanager\app\Services\ImageScanner;

class MediaManagerController extends Controller
{
    protected ImageScanner $scanner;

    public function __construct()
    {
        $this->scanner = new ImageScanner;
    }

    /**
     * Check if plugin tables are installed + ensure columns exist.
     */
    protected function isInstalled(): bool
    {
        try {
            $installed = Schema::hasTable('plugin_mediamanager_files')
                && Schema::hasTable('plugin_mediamanager_logs');

            if ($installed) {
                $this->ensureColumns();
            }

            return $installed;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Dashboard
     */
    public function index()
    {
        if (! $this->isInstalled()) {
            if (demo_mode()) {
                app_alert_error('This action is disabled in demo mode.');

                return redirect()->back();
            }
            try {
                $this->createTables();
            } catch (\Exception $e) {
                app_alert_error('Auto-installation failed: '.$e->getMessage());

                return redirect()->back();
            }
        }

        $summary = $this->scanner->getSummary();
        $lastScan = MediaScanFile::query()->max('scanned_at');

        // Directory breakdown
        $directoryStats = MediaScanFile::query()
            ->selectRaw('directory, status, COUNT(*) as count, SUM(size) as total_size')
            ->groupBy('directory', 'status')
            ->get()
            ->groupBy('directory')
            ->map(function ($statuses, $dir) {
                $totalSize = $statuses->sum('total_size');
                $totalCount = $statuses->sum('count');

                return [
                    'directory' => $dir,
                    'total_count' => $totalCount,
                    'total_size' => $totalSize,
                    'used_count' => $statuses->where('status', 'used')->sum('count'),
                    'orphaned_count' => $statuses->where('status', 'orphaned')->sum('count'),
                    'orphaned_size' => $statuses->where('status', 'orphaned')->sum('total_size'),
                    'other_count' => $totalCount
                        - $statuses->where('status', 'used')->sum('count')
                        - $statuses->where('status', 'orphaned')->sum('count'),
                ];
            })
            ->sortByDesc('total_size')
            ->values();

        // Trash info
        $trashFiles = $this->scanner->getTrashFiles();
        $trashSize = collect($trashFiles)->sum('size');

        return view('mediamanager::admin.dashboard', compact('summary', 'lastScan', 'directoryStats', 'trashFiles', 'trashSize'));
    }

    /**
     * Orphans
     */
    public function orphans(Request $request)
    {
        if (! $this->isInstalled()) {
            return redirect()->route('admin.mediamanager.install');
        }

        $query = MediaScanFile::orphans()->orderBy('size', 'desc');
        $directory = $request->input('directory');
        $search = $request->input('search');
        $sortBy = $request->input('sort', 'size');
        $sortDir = $request->input('dir', 'desc');

        if ($directory) {
            $query->where('directory', $directory);
        }
        if ($search) {
            $query->where('filename', 'like', "%{$search}%");
        }

        $allowedSorts = ['size', 'filename', 'modified_at', 'directory'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $files = $query->paginate(50)->withQueryString();
        $totalCount = $query->count();

        $directories = MediaScanFile::orphans()
            ->selectRaw('directory, COUNT(*) as count, SUM(size) as total_size')
            ->groupBy('directory')
            ->orderBy('total_size', 'desc')
            ->get();

        return view('mediamanager::admin.orphans', compact('files', 'directories', 'directory', 'search', 'sortBy', 'sortDir', 'totalCount'));
    }

    /**
     * Used files
     */
    public function used(Request $request)
    {
        if (! $this->isInstalled()) {
            return redirect()->route('admin.mediamanager.install');
        }

        $query = MediaScanFile::used()->orderBy('directory');
        $directory = $request->input('directory');
        $search = $request->input('search');

        if ($directory) {
            $query->where('directory', $directory);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('filename', 'like', "%{$search}%")
                    ->orWhere('reference_name', 'like', "%{$search}%")
                    ->orWhere('reference_owner', 'like', "%{$search}%");
            });
        }

        $files = $query->paginate(50)->withQueryString();

        $directories = MediaScanFile::used()
            ->selectRaw('directory, COUNT(*) as count, SUM(size) as total_size')
            ->groupBy('directory')
            ->orderBy('total_size', 'desc')
            ->get();

        return view('mediamanager::admin.used', compact('files', 'directories', 'directory', 'search'));
    }

    /**
     * Unknown files (no referenceMap for their directory)
     */
    public function unknown(Request $request)
    {
        if (! $this->isInstalled()) {
            return redirect()->route('admin.mediamanager.install');
        }

        $search = $request->input('search');
        $directory = $request->input('directory');

        $query = MediaScanFile::unknown()
            ->orderBy('size', 'desc');

        if ($directory) {
            $query->where('directory', $directory);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('filename', 'like', "%{$search}%")
                    ->orWhere('full_path', 'like', "%{$search}%");
            });
        }

        $files = $query->paginate(50)->withQueryString();

        $directories = MediaScanFile::unknown()
            ->selectRaw('directory, COUNT(*) as count, SUM(size) as total_size')
            ->groupBy('directory')
            ->orderBy('total_size', 'desc')
            ->get();

        return view('mediamanager::admin.unknown', compact('files', 'directories', 'directory', 'search'));
    }

    /**
     * Duplicates
     */
    public function duplicates(Request $request)
    {
        if (! $this->isInstalled()) {
            return redirect()->route('admin.mediamanager.install');
        }

        $groupId = $request->input('group');

        $query = MediaScanFile::where('is_duplicate', true);

        if ($groupId) {
            $query->where('duplicate_group_id', $groupId);
            $files = $query->orderBy('size', 'desc')->paginate(50)->withQueryString();
        } else {
            // Show summary: one row per group
            $groups = $query
                ->selectRaw('duplicate_group_id, COUNT(*) as count, MIN(size) as size, MIN(full_path) as sample_path, MIN(directory) as directory')
                ->groupBy('duplicate_group_id')
                ->orderBy('size', 'desc')
                ->paginate(30);

            $files = null;

            return view('mediamanager::admin.duplicates', compact('groups', 'files'));
        }

        $groups = null;

        return view('mediamanager::admin.duplicates', compact('groups', 'files'));
    }

    /**
     * Image detail (AJAX)
     */
    public function imageInfo(Request $request)
    {
        $fullPath = $request->input('path');
        if (! $fullPath) {
            return response()->json(['error' => 'No path provided'], 400);
        }

        $file = MediaScanFile::where('full_path', $fullPath)->first();
        if (! $file) {
            return response()->json(['error' => 'File not found in scan results'], 404);
        }

        $absolutePath = base_path('../'.$file->full_path);
        $info = $this->scanner->getImageInfo($absolutePath);
        $info['file'] = $file;

        return response()->json($info);
    }

    /**
     * Toggle protection on a file
     */
    public function toggleProtect(Request $request)
    {
        $id = $request->input('id');
        $file = MediaScanFile::find($id);

        if (! $file) {
            app_alert_error('File not found.');

            return redirect()->back();
        }

        $file->is_protected = ! $file->is_protected;
        $file->status = $file->is_protected ? 'protected' : 'orphaned';
        $file->save();

        $state = $file->is_protected ? 'protected' : 'orphaned';
        app_alert_success("File marked as {$state}.");

        return redirect()->back();
    }

    /**
     * Delete selected orphaned files. Supports "select all across pages" mode.
     */
    public function deleteOrphans(Request $request)
    {
        if (demo_mode()) {
            app_alert_error('This action is disabled in demo mode.');

            return redirect()->back();
        }

        $mode = $request->input('mode', 'trash');
        $selectAll = $request->boolean('select_all');

        if ($selectAll) {
            // Delete ALL matching orphans (respecting current filters)
            $query = MediaScanFile::orphans()
                ->where('is_protected', false);

            if ($request->filled('directory')) {
                $query->where('directory', $request->input('directory'));
            }
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('filename', 'like', "%{$search}%")
                        ->orWhere('full_path', 'like', "%{$search}%");
                });
            }

            $files = $query->get();
        } else {
            $ids = $request->input('file_ids', []);
            if (empty($ids)) {
                app_alert_error('No files selected.');

                return redirect()->back();
            }
            $files = MediaScanFile::whereIn('id', $ids)
                ->where('status', 'orphaned')
                ->get();
        }

        $deletedCount = 0;
        $deletedSize = 0;
        $failed = [];

        foreach ($files as $file) {
            if ($file->is_protected) {
                $failed[] = $file->filename.' (protected)';

                continue;
            }
            try {
                $success = $mode === 'permanent'
                    ? $this->scanner->deletePermanently($file)
                    : $this->scanner->moveToTrash($file);

                if ($success) {
                    MediaCleanupLog::logDelete(
                        Auth::id(),
                        $file->full_path,
                        $file->size,
                        json_encode(['mode' => $mode, 'select_all' => $selectAll])
                    );
                    if ($mode === 'trash') {
                        $file->is_trashed = true;
                        $file->trashed_at = now();
                        $file->save();
                    } else {
                        $file->delete();
                    }
                    $deletedCount++;
                    $deletedSize += $file->size;
                } else {
                    $failed[] = $file->filename;
                }
            } catch (\Exception $e) {
                $failed[] = $file->filename.' ('.$e->getMessage().')';
            }
        }

        $message = ImageScanner::formatBytes($deletedSize).' freed from '.$deletedCount.' files.';
        if (! empty($failed)) {
            $message .= ' Failed: '.implode(', ', array_slice($failed, 0, 5));
        }

        app_alert_success($message);

        return redirect()->back();
    }

    /**
     * Delete unknown files (files not tracked by any referenceMap)
     */
    public function deleteUnknown(Request $request)
    {
        if (demo_mode()) {
            app_alert_error('This action is disabled in demo mode.');

            return redirect()->back();
        }

        $ids = $request->input('file_ids', []);
        $mode = $request->input('mode', 'trash');

        if (empty($ids)) {
            app_alert_error('No files selected.');

            return redirect()->back();
        }

        $files = MediaScanFile::whereIn('id', $ids)
            ->where('status', 'unknown')
            ->where('is_protected', false)
            ->get();

        $deletedCount = 0;
        $deletedSize = 0;
        $failed = [];

        foreach ($files as $file) {
            try {
                $success = $mode === 'permanent'
                    ? $this->scanner->deletePermanently($file)
                    : $this->scanner->moveToTrash($file);

                if ($success) {
                    MediaCleanupLog::logDelete(
                        Auth::id(),
                        $file->full_path,
                        $file->size,
                        json_encode(['mode' => $mode, 'reason' => 'unknown'])
                    );
                    if ($mode === 'trash') {
                        $file->is_trashed = true;
                        $file->trashed_at = now();
                        $file->save();
                    } else {
                        $file->delete();
                    }
                    $deletedCount++;
                    $deletedSize += $file->size;
                } else {
                    $failed[] = $file->filename;
                }
            } catch (\Exception $e) {
                $failed[] = $file->filename.' ('.$e->getMessage().')';
            }
        }

        $message = ImageScanner::formatBytes($deletedSize).' freed from '.$deletedCount.' unknown files.';
        if (! empty($failed)) {
            $message .= ' Failed: '.implode(', ', array_slice($failed, 0, 5));
        }

        app_alert_success($message);

        return redirect()->back();
    }

    /**
     * Delete one duplicate group (keep the oldest file)
     */
    public function deleteDuplicateGroup(Request $request)
    {
        if (demo_mode()) {
            app_alert_error('This action is disabled in demo mode.');

            return redirect()->back();
        }

        $groupId = $request->input('group_id');
        if (! $groupId) {
            app_alert_error('No group specified.');

            return redirect()->back();
        }

        $files = MediaScanFile::where('duplicate_group_id', $groupId)
            ->where('is_duplicate', true)
            ->orderBy('modified_at', 'asc')
            ->get();

        if ($files->count() < 2) {
            app_alert_error('Not enough duplicates in this group.');

            return redirect()->back();
        }

        // Keep the first (oldest), delete the rest
        $keep = $files->first();
        $toDelete = $files->slice(1);

        $deletedCount = 0;
        $deletedSize = 0;

        foreach ($toDelete as $file) {
            try {
                $success = $this->scanner->deletePermanently($file);
                if ($success) {
                    MediaCleanupLog::logDelete(Auth::id(), $file->full_path, $file->size, json_encode(['mode' => 'permanent', 'reason' => 'duplicate']));
                    $file->delete();
                    $deletedCount++;
                    $deletedSize += $file->size;
                }
            } catch (\Exception $e) {
                // skip
            }
        }

        app_alert_success("Deleted {$deletedCount} duplicates, kept {$keep->filename}. Freed ".ImageScanner::formatBytes($deletedSize).'.');

        return redirect()->back();
    }

    /**
     * View trashed files
     */
    public function trash()
    {
        if (! $this->isInstalled()) {
            return redirect()->route('admin.mediamanager.install');
        }

        $trashFiles = $this->scanner->getTrashFiles();
        $totalSize = array_sum(array_column($trashFiles, 'size'));

        return view('mediamanager::admin.trash', compact('trashFiles', 'totalSize'));
    }

    /**
     * Restore file from trash
     */
    public function restoreFromTrash(Request $request)
    {
        $path = $request->input('path');
        if (! $path) {
            app_alert_error('No file specified.');

            return redirect()->back();
        }

        // Normalize: remove any leading storage/ prefix(es)
        $normalizedPath = preg_replace('/^(storage\/)+/', '', $path);

        // Try exact path as provided (backward compat for old double-prefixed trash)
        $trashPath = base_path('../storage/.mediamanager-trash/'.$path);
        if (! file_exists($trashPath)) {
            // Try without leading storage/ prefix
            $trashPath = base_path('../storage/.mediamanager-trash/'.$normalizedPath);
        }
        if (! file_exists($trashPath)) {
            app_alert_error('File not found in trash.');

            return redirect()->back();
        }

        $originalPath = base_path('../storage/'.$normalizedPath);
        $dir = dirname($originalPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (rename($trashPath, $originalPath)) {
            // Update DB record
            $dbPath = 'storage/'.$normalizedPath;
            $dbFile = MediaScanFile::where('full_path', $dbPath)
                ->orWhere('full_path', $normalizedPath)
                ->first();
            if ($dbFile) {
                $dbFile->is_trashed = false;
                $dbFile->trashed_at = null;
                $dbFile->save();
            }
            app_alert_success('File restored successfully.');
        } else {
            app_alert_error('Failed to restore file.');
        }

        return redirect()->back();
    }

    /**
     * Empty trash
     */
    public function emptyTrash()
    {
        if (demo_mode()) {
            app_alert_error('This action is disabled in demo mode.');

            return redirect()->back();
        }

        // Delete DB records first
        MediaScanFile::where('is_trashed', true)->delete();

        $trashPath = base_path('../storage/.mediamanager-trash');
        if (! is_dir($trashPath)) {
            app_alert_success('Trash is already empty.');

            return redirect()->back();
        }

        $this->deleteDirectoryRecursive($trashPath);
        app_alert_success('Trash emptied.');

        return redirect()->back();
    }

    /**
     * View cleanup logs
     */
    public function logs()
    {
        if (! $this->isInstalled()) {
            return redirect()->route('admin.mediamanager.install');
        }

        $logs = MediaCleanupLog::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return view('mediamanager::admin.logs', compact('logs'));
    }

    // ---- Scan AJAX methods ----

    public function scanInit()
    {
        try {
            if (! $this->isInstalled()) {
                return response()->json(['error' => 'Plugin not installed'], 403);
            }

            $this->ensureColumns();

            $directories = $this->scanner->getScanDirectories();
            $this->scanner->clearPreviousScans();

            return response()->json([
                'total_directories' => count($directories),
                'directories' => $directories,
            ]);
        } catch (\Throwable $e) {
            Log::error('MediaManager scanInit error: '.$e->getMessage());

            return response()->json(['error' => 'Scan initialization failed: '.$e->getMessage()], 500);
        }
    }

    public function scan(Request $request)
    {
        try {
            if (! $this->isInstalled()) {
                return response()->json(['error' => 'Plugin not installed'], 403);
            }

            $directory = $request->input('directory');
            $directories = $this->scanner->getScanDirectories();

            if (! in_array($directory, $directories)) {
                return response()->json(['error' => 'Invalid directory: '.$directory], 400);
            }

            $stats = $this->scanner->scanDirectory($directory);

            return response()->json(['success' => true, 'directory' => $directory, 'stats' => $stats]);
        } catch (\Throwable $e) {
            Log::error('MediaManager scan error: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['success' => false, 'directory' => $directory ?? '', 'error' => $e->getMessage()], 500);
        }
    }

    public function scanComplete()
    {
        try {
            if (! $this->isInstalled()) {
                return response()->json(['error' => 'Plugin not installed'], 403);
            }

            // Run duplicate detection with a 25-second limit to avoid 504 Gateway Timeout
            $dupesFound = $this->scanner->detectDuplicates(25);

            $summary = $this->scanner->getSummary();

            MediaCleanupLog::logScan(
                Auth::id(),
                json_encode([
                    'total_files' => $summary['total']['count'] ?? 0,
                    'orphaned_files' => $summary['orphaned']['count'] ?? 0,
                    'orphaned_size' => $summary['orphaned']['size'] ?? 0,
                    'duplicates' => $summary['duplicates']['count'] ?? 0,
                ])
            );

            return response()->json([
                'success' => true,
                'summary' => $summary,
                'duplicates_found' => $dupesFound,
                'redirect' => route('admin.mediamanager.orphans'),
            ]);
        } catch (\Throwable $e) {
            Log::error('MediaManager scanComplete error: '.$e->getMessage());

            return response()->json(['error' => 'Scan completion failed: '.$e->getMessage()], 500);
        }
    }

    // ---- Helpers ----

    public function directories()
    {
        if (! $this->isInstalled()) {
            return response()->json([]);
        }

        $directories = MediaScanFile::selectRaw('directory, COUNT(*) as count, SUM(size) as total_size')
            ->groupBy('directory')
            ->orderBy('directory')
            ->get();

        return response()->json($directories);
    }

    /**
     * Create plugin tables.
     */
    protected function createTables(): void
    {
        if (! Schema::hasTable('plugin_mediamanager_files')) {
            Schema::create('plugin_mediamanager_files', function ($table) {
                $table->id();
                $table->string('directory');
                $table->string('filename');
                $table->string('full_path');
                $table->unsignedBigInteger('size')->default(0);
                $table->timestamp('modified_at')->nullable();
                $table->boolean('is_referenced')->default(false);
                $table->string('reference_table')->nullable();
                $table->string('reference_column')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('reference_name')->nullable();
                $table->string('reference_owner')->nullable();
                $table->boolean('is_duplicate')->default(false);
                $table->unsignedBigInteger('duplicate_group_id')->nullable();
                $table->boolean('is_protected')->default(false);
                $table->string('status')->default('unknown');
                $table->timestamp('scanned_at')->useCurrent();
                $table->timestamps();

                $table->index(['directory', 'status']);
                $table->index(['status', 'created_at']);
                $table->index(['is_referenced', 'status']);
                $table->index('is_duplicate');
                $table->index('is_protected');
            });
        }

        if (! Schema::hasTable('plugin_mediamanager_logs')) {
            Schema::create('plugin_mediamanager_logs', function ($table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('action');
                $table->text('file_path');
                $table->unsignedBigInteger('file_size')->default(0);
                $table->text('details')->nullable();
                $table->timestamps();

                $table->index(['action', 'created_at']);
                $table->index('user_id');
            });
        }
    }

    /**
     * Ensure all columns exist on the plugin tables (auto-migration).
     */
    protected function ensureColumns(): void
    {
        $this->createTables();

        // Add missing columns to plugin_mediamanager_files
        $columnNames = Schema::getColumnListing('plugin_mediamanager_files');

        $adds = [
            'modified_at' => function () {
                Schema::table('plugin_mediamanager_files', function ($t) {
                    $t->timestamp('modified_at')->nullable();
                });
            },
            'reference_name' => function () {
                Schema::table('plugin_mediamanager_files', function ($t) {
                    $t->string('reference_name')->nullable();
                });
            },
            'reference_owner' => function () {
                Schema::table('plugin_mediamanager_files', function ($t) {
                    $t->string('reference_owner')->nullable();
                });
            },
            'is_duplicate' => function () {
                Schema::table('plugin_mediamanager_files', function ($t) {
                    $t->boolean('is_duplicate')->default(false);
                });
            },
            'duplicate_group_id' => function () {
                Schema::table('plugin_mediamanager_files', function ($t) {
                    $t->unsignedBigInteger('duplicate_group_id')->nullable();
                });
            },
            'is_protected' => function () {
                Schema::table('plugin_mediamanager_files', function ($t) {
                    $t->boolean('is_protected')->default(false);
                });
            },
        ];

        foreach ($adds as $col => $adder) {
            if (! in_array($col, $columnNames)) {
                $adder();
            }
        }

        // Add missing columns to plugin_mediamanager_logs
        if (Schema::hasTable('plugin_mediamanager_logs')) {
            $logColumnNames = Schema::getColumnListing('plugin_mediamanager_logs');

            if (! in_array('file_size', $logColumnNames)) {
                Schema::table('plugin_mediamanager_logs', function ($t) {
                    $t->unsignedBigInteger('file_size')->default(0);
                });
            }
        }
    }

    /**
     * Recursively delete a directory.
     */
    protected function deleteDirectoryRecursive(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }

    // ---- Installation & Settings ----

    /**
     * Installation page (shown when tables don't exist yet).
     */
    public function install()
    {
        $this->createTables();

        return view('mediamanager::admin.install');
    }

    /**
     * Enable the plugin (activate in options table).
     */
    public function enable()
    {
        $this->createTables();

        try {
            $raw = Option::selectOptions('active_plugins');
            $active = $raw ? json_decode($raw, true) : [];
            if (! is_array($active)) {
                $active = [];
            }

            if (! in_array('mediamanager', $active)) {
                $active[] = 'mediamanager';
            }

            Option::updateOptions('active_plugins', $active);

            // Clear caches
            if (function_exists('cache')) {
                cache()->forget('settings');
                cache()->forget('plugins');
            }

            return redirect()->route('admin.mediamanager.index')
                ->with('success', 'Media Manager plugin enabled successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to enable plugin: '.$e->getMessage());
        }
    }

    /**
     * Disable the plugin (deactivate in options table).
     */
    public function disable()
    {
        try {
            $raw = Option::selectOptions('active_plugins');
            $active = $raw ? json_decode($raw, true) : [];
            if (! is_array($active)) {
                $active = [];
            }

            $active = array_values(array_diff($active, ['mediamanager']));

            Option::updateOptions('active_plugins', $active);

            if (function_exists('cache')) {
                cache()->forget('settings');
                cache()->forget('plugins');
            }

            return redirect()->route('admin.mediamanager.install')
                ->with('success', 'Media Manager plugin disabled.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to disable plugin: '.$e->getMessage());
        }
    }

    /**
     * Plugin settings page.
     */
    public function settings()
    {
        if (! $this->isInstalled()) {
            return redirect()->route('admin.mediamanager.install');
        }

        $allDirectories = $this->scanner->getScanDirectories();

        // Load saved settings — what directories are currently enabled
        $savedRaw = Option::selectOptions('mediamanager_scan_directories');
        $savedDirectories = $savedRaw ? json_decode($savedRaw, true) : null;

        // If nothing saved yet, enable all by default
        if (! is_array($savedDirectories)) {
            $savedDirectories = $allDirectories;
        }

        return view('mediamanager::admin.settings', compact('allDirectories', 'savedDirectories'));
    }

    /**
     * Save plugin settings.
     */
    public function saveSettings(Request $request)
    {
        if (! $this->isInstalled()) {
            return redirect()->route('admin.mediamanager.install');
        }

        $request->validate([
            'scan_directories' => 'required|array|min:1',
        ]);

        Option::updateOptions('mediamanager_scan_directories', $request->scan_directories);

        return redirect()->route('admin.mediamanager.settings')
            ->with('success', 'Settings saved successfully.');
    }

    /**
     * Serve plugin assets (CSS/JS).
     */
    public function assets($file)
    {
        $path = plugin_path('mediamanager/assets/'.$file);

        if (! file_exists($path)) {
            abort(404);
        }

        $mime = mime_content_type($path) ?: 'application/octet-stream';
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        $cacheHeaders = [
            'css' => 'max-age=86400',
            'js' => 'max-age=86400',
        ];

        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => $cacheHeaders[$ext] ?? 'no-cache',
        ];

        return response()->file($path, $headers);
    }
}
