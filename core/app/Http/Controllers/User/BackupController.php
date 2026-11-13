<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\User;

use App\Services\Backup\StoreBackupService;
use App\Services\Backup\StoreImportService;
use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Jobs\GenerateStoreBackupJob;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreBackup;
use Juwri\Core\Models\StoreBackupSchedule;
use Juwri\Core\Services\Account\AccountExports;
use Juwri\Core\Services\Ops\BackupCrypto;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\UploadLimit;
use Juwri\Modules\Integrations\Jobs\ProcessStoreImportJob;
use Juwri\Modules\Integrations\Models\StoreImport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Self-service backup/export for the owner's OWN restaurant. Every action is
 * scoped to the current dashboard store AND re-checks that the store belongs to
 * the authenticated owner, so a guessed token or store id can never reach
 * another owner's archive.
 */
class BackupController extends Controller
{
    public function __construct()
    {
        $this->activeTheme = active_theme();
    }

    /** The current dashboard store, guaranteed owned by the auth user, or 404. */
    private function ownedStore(): Store
    {
        $store = dashboard_current_store();
        abort_unless($store, 404, ___('Pick a store first.'));
        abort_unless((int) $store->user_id === (int) auth()->id(), 403);

        return $store;
    }

    public function index()
    {
        $store = $this->ownedStore();

        $backups = StoreBackup::where('store_id', $store->id)
            ->where('user_id', auth()->id())
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $schedule = StoreBackupSchedule::where('store_id', $store->id)
            ->where('user_id', auth()->id())
            ->first();

        $imports = StoreImport::where('store_id', $store->id)
            ->where('user_id', auth()->id())
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('owner.settings.backup', [
            'store' => $store,
            'backups' => $backups,
            'schedule' => $schedule,
            'imports' => $imports,
            'branches' => Branch::where('store_id', $store->id)->orderBy('id')->get(['id', 'name']),
            'datasets' => StoreBackupService::DATASETS,
            'piiSets' => StoreBackupService::PII_DATASETS,
            'groups' => StoreBackupService::GROUPS,
            'canEncrypt' => StoreBackupService::encryptionAvailable(),
            // P6-212: the import form's MB hint shows the host's real cap, not a
            // claimed 500 MB. A shared host pinned to 16 MB is going to fail at
            // 16 MB regardless of what the form says — tell the owner up front.
            'hostUploadMaxMb' => UploadLimit::effectiveMaxMb(),
            'fullPageTrial' => StoreSettings::bool((int) $store->id, 'storefront_fullpage_cache_trial', false),
            'accountExport' => ($aid = app(AccountExports::class)->accountOf(auth()->user())) !== null
                ? ['allowed' => true, 'latest' => app(AccountExports::class)->latest($aid)] : ['allowed' => false, 'latest' => null],
        ]);
    }

    /**
     * WI-E1: upload a backup ZIP → validate + dry-run preview. Nothing is written
     * to the store yet; the owner reviews the plan and then confirms.
     */
    public function importUpload(Request $request, StoreImportService $service)
    {
        $store = $this->ownedStore();
        $request->validate([
            'archive' => 'required|file|mimes:zip|max:512000', // 500 MB
        ]);

        $token = StoreImport::newToken();
        $relPath = 'store-imports/'.$store->id.'/'.$token.'.zip';
        $request->file('archive')->storeAs('store-imports/'.$store->id, $token.'.zip');

        $import = StoreImport::create([
            'store_id' => $store->id,
            'user_id' => auth()->id(),
            'token' => $token,
            'status' => 'validating',
            'path' => $relPath,
            'size_bytes' => $request->file('archive')->getSize(),
        ]);

        try {
            [$manifest, $crossStore] = $service->validate($import, $store);
            $plan = $service->plan($import, $store);
            $import->update([
                'status' => 'previewed',
                'manifest' => $manifest,
                'plan' => $plan,
                'cross_store' => $crossStore,
            ]);

            return back()->with('success', ___('Backup uploaded. Review what will be imported, then confirm.'));
        } catch (\Throwable $e) {
            report($e);
            $import->update(['status' => 'failed', 'error' => ___('The backup file could not be imported.')]);
            @unlink(storage_path('app/'.$relPath));

            return back()->with('error', ___('The backup file could not be imported.'));
        }
    }

    /** WI-E1: confirm a previewed import → apply on the heavy queue. */
    public function importConfirm(Request $request, string $token)
    {
        $store = $this->ownedStore();
        $import = StoreImport::where('token', $token)
            ->where('store_id', $store->id)
            ->where('user_id', auth()->id())
            ->first();
        abort_unless($import && $import->isPreviewed(), 404);

        // HPMS E14(e): the confirm form's dataset picker. Absent → all safe datasets.
        $data = $request->validate([
            'datasets' => ['nullable', 'array'],
            'datasets.*' => ['string', 'in:'.implode(',', array_merge(StoreBackupService::DATASETS, ['media']))],
        ]);
        $only = array_key_exists('datasets', $data) ? array_values($data['datasets']) : null;

        /* P6-316: claim previewed -> processing in ONE conditional update; a second click (or tab) finds no
           previewed row and dispatches nothing, so the backup is never applied twice. */
        $claimed = StoreImport::whereKey($import->id)->where('status', 'previewed')
            ->update(['status' => 'processing', 'only_datasets' => $only === null ? null : json_encode($only)]);
        if ($claimed !== 1) {
            return back()->with('success', ___('Importing… this can take a moment for a large store.'));
        }
        ProcessStoreImportJob::dispatch($import->id);

        return back()->with('success', ___('Importing… this can take a moment for a large store.'));
    }

    /** WI-E1: import status poll (JSON). */
    public function importStatus(string $token)
    {
        $store = $this->ownedStore();
        $import = StoreImport::where('token', $token)
            ->where('store_id', $store->id)
            ->where('user_id', auth()->id())
            ->first();
        abort_unless($import, 404);

        return response()->json([
            'status' => $import->status,
            'summary' => $import->summary,
            'error' => $import->error,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /** WI-E1: discard an upload and its file. */
    public function importDestroy(string $token)
    {
        $store = $this->ownedStore();
        $import = StoreImport::where('token', $token)
            ->where('store_id', $store->id)
            ->where('user_id', auth()->id())
            ->first();
        abort_unless($import, 404);
        // P6-316: the running job reads this file — never pull it out from under it.
        if ($import->status === 'processing') {
            return back()->with('error', ___('This backup is being imported. Wait until it finishes.'));
        }

        if ($import->path) {
            @unlink(storage_path('app/'.$import->path));
        }
        $import->delete();

        return back()->with('success', ___('Upload discarded.'));
    }

    /** Validate the shared option set (used by both manual and scheduled). */
    private function validateOptions(Request $request, Store $store): array
    {
        $data = $request->validate([
            'datasets' => 'nullable|array',
            'datasets.*' => 'string|in:'.implode(',', StoreBackupService::DATASETS),
            'format' => 'nullable|in:both,json,csv',
            'orders_window' => 'nullable|in:all,30,90,custom',
            'orders_from' => 'nullable|date',
            'orders_to' => 'nullable|date|after_or_equal:orders_from',
            'branch_id' => 'nullable|integer',
        ]);

        // A branch, if given, must belong to this store.
        $branchId = null;
        if (! empty($data['branch_id'])
            && Branch::where('store_id', $store->id)->where('id', $data['branch_id'])->exists()) {
            $branchId = (int) $data['branch_id'];
        }

        // Resolve the order window into concrete dates.
        [$from, $to] = [null, null];
        switch ($data['orders_window'] ?? 'all') {
            case '30': $from = now()->subDays(30)->toDateString();
                break;
            case '90': $from = now()->subDays(90)->toDateString();
                break;
            case 'custom':
                $from = $data['orders_from'] ?? null;
                $to = $data['orders_to'] ?? null;
                break;
        }

        $datasets = $data['datasets'] ?? array_values(array_diff(StoreBackupService::DATASETS, StoreBackupService::PII_DATASETS));

        return [
            'datasets' => array_values(array_intersect(StoreBackupService::DATASETS, $datasets)),
            'format' => $data['format'] ?? 'both',
            'branch_id' => $branchId,
            'orders_from' => $from,
            'orders_to' => $to,
        ];
    }

    public function store(Request $request)
    {
        $store = $this->ownedStore();
        $opts = $this->validateOptions($request, $store);
        $request->validate([
            'password' => 'nullable|string|min:6|max:128',
            'notify' => 'nullable|boolean',
        ]);

        // One in-flight backup per store at a time — stops a queue pile-up and
        // makes the "please wait" UX truthful.
        $active = StoreBackup::where('store_id', $store->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();
        if ($active) {
            return back()->with('error', ___('A backup is already being prepared. Please wait for it to finish.'));
        }

        $password = trim((string) $request->input('password'));
        $notifyEmail = $request->boolean('notify') ? (auth()->user()->email ?? null) : null;

        $backup = StoreBackup::create([
            'store_id' => $store->id,
            'user_id' => auth()->id(),
            'token' => StoreBackup::newToken(),
            'status' => 'pending',
            'datasets' => $opts['datasets'],
            'include_pii' => (bool) array_intersect($opts['datasets'], StoreBackupService::PII_DATASETS),
            'format' => $opts['format'],
            'branch_id' => $opts['branch_id'],
            'orders_from' => $opts['orders_from'],
            'orders_to' => $opts['orders_to'],
            'encrypted' => $password !== '',
            'password_enc' => $password !== '' ? Crypt::encryptString($password) : null,
            'notify_email' => $notifyEmail,
        ]);

        StoreBackup::pruneForStore($store->id, (int) auth()->id());

        GenerateStoreBackupJob::dispatch($backup->id);

        return back()->with('success', ___('Your backup is being prepared. It will appear below when ready.'));
    }

    /** Create or update the single recurring schedule for this store. */
    public function storeSchedule(Request $request)
    {
        $store = $this->ownedStore();
        $opts = $this->validateOptions($request, $store);
        $request->validate([
            'frequency' => 'required|in:weekly,monthly',
            'notify' => 'nullable|boolean',
        ]);

        $frequency = $request->input('frequency');
        $notifyEmail = $request->boolean('notify') ? (auth()->user()->email ?? null) : null;

        StoreBackupSchedule::updateOrCreate(
            ['store_id' => $store->id, 'user_id' => auth()->id()],
            [
                'frequency' => $frequency,
                'datasets' => $opts['datasets'],
                'format' => $opts['format'],
                'branch_id' => $opts['branch_id'],
                'orders_window' => $request->input('orders_window', 'all'),
                'notify_email' => $notifyEmail,
                'active' => true,
                'next_run_at' => StoreBackupSchedule::nextRunFor($frequency),
            ]
        );

        return back()->with('success', ___('Automatic backups are on. We\'ll prepare one on your schedule.'));
    }

    public function destroySchedule()
    {
        $store = $this->ownedStore();
        StoreBackupSchedule::where('store_id', $store->id)
            ->where('user_id', auth()->id())
            ->delete();

        return back()->with('success', ___('Automatic backups turned off.'));
    }

    /** Status poll (JSON). Scoped to the owner + current store. */
    public function status(string $token)
    {
        $store = $this->ownedStore();
        $backup = StoreBackup::where('token', $token)
            ->where('store_id', $store->id)
            ->where('user_id', auth()->id())
            ->first();

        abort_unless($backup, 404);

        $data = [
            'status' => $backup->isExpired() ? 'expired' : $backup->status,
            'size' => $backup->size_bytes,
            'progress' => (int) $backup->progress,
            'step' => $backup->current_step,
            'error' => $backup->error,
        ];
        if ($backup->isReady()) {
            $data['download_url'] = route('backup.download', $backup->token);
        }

        return response()->json($data, 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /** P4-07: start the account export (every store of the account, all its data and files; secrets left out). */
    public function accountExport(AccountExports $exports): RedirectResponse
    {
        $accountId = $exports->accountOf(auth()->user());
        abort_unless($accountId !== null, 403);
        $exports->start($accountId);

        return back()->with('success', ___('Your account export is being prepared. It appears below when it is ready.'));
    }

    /**
     * Owner decision 2026-10-08: the full-page cache stays off for everyone; the account OWNER can trial it for one store
     * (TTL 300 s). Not a team member, not a staff session.
     */
    public function fullPageCacheTrial(Request $request, AccountExports $exports): RedirectResponse
    {
        abort_unless($exports->accountOf(auth()->user()) !== null, 403);
        abort_if(session()->has('staff_session_id'), 403);
        $store = $this->ownedStore();
        $on = $request->boolean('enabled');
        StoreSettings::setMany((int) $store->id, ['storefront_fullpage_cache_trial' => $on]);
        StorefrontMenuCache::bust((int) $store->id);

        return back()->with('success', $on ? ___('Page caching is on for this store (5 minutes).') : ___('Page caching is off for this store.'));
    }

    /** P4-07: download it — looked up in the signed-in owner's own account folder only. */
    public function accountExportDownload(AccountExports $exports, string $token): BinaryFileResponse
    {
        $accountId = $exports->accountOf(auth()->user());
        abort_unless($accountId !== null, 404);
        $file = $exports->file($accountId, $token);
        abort_unless($file !== null, 404);

        return $this->sendArchive($file, 'account-export-'.date('Y-m-d').'.zip');
    }

    public function download(string $token)
    {
        $store = $this->ownedStore();
        $backup = StoreBackup::where('token', $token)
            ->where('store_id', $store->id)
            ->where('user_id', auth()->id())
            ->first();

        abort_unless($backup && $backup->isReady(), 404);

        $abs = storage_path('app/'.$backup->path);
        abort_unless(is_file($abs), 404);

        return $this->sendArchive($abs, $backup->downloadName());
    }

    /**
     * P6-96b: archives are encrypted at rest (JWBK) when BACKUP_ENCRYPTION_KEY is set. Decrypt + authenticate the
     * whole file into a temp copy (current or previous key), send it, delete it after. Wrong key / tampering fails
     * loudly (reported, 500) — never a partial or unauthenticated archive. Plain archives (no key, or made before
     * the key) are sent as they are.
     */
    private function sendArchive(string $abs, string $name): BinaryFileResponse
    {
        try {
            [$plain, $temporary] = BackupCrypto::plaintextCopy($abs, storage_path('app/tmp/backup-downloads'));
        } catch (\Throwable $e) {
            report($e);
            abort(500);
        }

        return response()->download($plain, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend($temporary);
    }

    public function destroy(string $token)
    {
        $store = $this->ownedStore();
        $backup = StoreBackup::where('token', $token)
            ->where('store_id', $store->id)
            ->where('user_id', auth()->id())
            ->first();

        abort_unless($backup, 404);

        if ($backup->path) {
            @unlink(storage_path('app/'.$backup->path));
        }
        $backup->delete();

        return back()->with('success', ___('Backup deleted.'));
    }
}
