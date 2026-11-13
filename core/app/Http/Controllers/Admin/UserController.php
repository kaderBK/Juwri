<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Admin;

use App\Jobs\DeleteUsersJob;
use App\Models\AppInstallation;
use App\Models\Order;
use App\Models\SellerWithdrawal;
use App\Models\Upgrade;
use App\Models\WebhookEndpoint;
use App\Services\Account\AccountDeletionService;
use App\Services\AppInstallService;
use App\Services\Backup\AdminStoreArchive;
use App\Services\Backup\DatasetStreamWriter;
use App\Services\Backup\StoreBackupService;
use App\Services\Backup\StorePurgeService;
use App\Support\HostCapabilities;
use App\Support\SearchTerm;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Contracts\Billing\Subscriptions;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Admin\Concerns\KeepsPanelImage;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Requests\Admin\Accounts\AccountStoreRequest;
use Juwri\Core\Http\Requests\Admin\Accounts\AccountUpdateRequest;
use Juwri\Core\Mail\SupportMessage;
use Juwri\Core\Mail\UserDetails;
use Juwri\Core\Models\Country;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Notification;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\LegacyMoneyColumns;
use Juwri\Core\Money\Money;
use Juwri\Core\Policies\Admin\PanelAbilities;
use Juwri\Core\Services\AbuseGuard;
use Juwri\Core\Services\AbuseHold;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Services\Tenancy\AccountProvisioner;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Core\Services\Tenancy\TenantTables;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Settings\UserSettings;
use Juwri\Core\Support\Admin\Cells;
use Juwri\Core\Support\BackupZipValidator;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\Sql;
use Juwri\Modules\Auth\Http\Requests\Admin\Accounts\AccountPasswordRequest;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogCategory;
use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Messaging\Http\Requests\Admin\Accounts\AccountMailRequest;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\StorePaymentGateways;
use Juwri\Modules\Payments\Services\WalletService;
use Laravel\Passport\AuthCode;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Symfony\Component\Process\Process;
use Validator;

class UserController extends Controller
{
    use AnswersAdminPanel;
    use KeepsPanelImage;

    /** P1-13 13g: the account plan actions are Billing's (ee) — it fills these slots and registers their routes. */
    public const PLAN_UPDATE_SLOT = 'billing.admin.account-plan.update';

    public const SUBSCRIPTION_PAUSE_SLOT = 'billing.admin.subscription.pause';

    public const SUBSCRIPTION_RESUME_SLOT = 'billing.admin.subscription.resume';

    private const AVATAR_FOLDER = 'storage/profile/';

    private const AVATAR_SIZE = '150x150';

    private const AVATAR_PLACEHOLDER = 'default_user.png';

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            Gate::authorize(PanelAbilities::ACCOUNTS);

            return $next($request);
        });
    }

    /** The accounts list: server-rendered page, or the DataTables answer for XHR. */
    public function index(Request $request): JsonResponse|View
    {
        $plans = app(PlanCatalog::class)->plansByPosition();

        if ($request->ajax()) {
            $query = $this->usersTableQuery($request);
            $totalRecords = User::count();
            $filteredRecords = (clone $query)->count();

            $this->applyUsersOrdering($query, $request);

            $start = max((int) $request->input('start', 0), 0);
            $length = (int) $request->input('length', 25);
            if ($length >= 0) {
                $query->skip($start)->take($length);
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, User> $users */
            $users = $query->get();
            $data = $users->map(function (User $row) use ($plans): array {
                $plan_label = $this->userPlanLabel($row, $plans);
                // Columns selected by usersTableQuery(), not model attributes.
                $planStartAt = $row->getAttribute('plan_start');
                $planEndAt = $row->getAttribute('plan_end');
                $planInterval = $row->getAttribute('plan_interval');

                // Plan Start
                if ($planStartAt && $planStartAt > 0) {
                    $plan_start = date_formating($planStartAt);
                } elseif (($row->plan_key ?? $row->group_id) === 'trial' && $row->created_at) {
                    $plan_start = date_formating(strtotime($row->created_at));
                } else {
                    $plan_start = '-';
                }

                // Plan End
                $trialDays = (int) ($row->trial_days ?? 7);
                if ($planEndAt && $planEndAt > 0) {
                    // Has an upgrade record
                    if ($planInterval === 'LIFETIME' || $planEndAt > 4000000000) {
                        $plan_end = '<span class="badge bg-primary">'.___('Lifetime').'</span>';
                    } elseif ($planEndAt < time() || ($row->plan_sub_status ?? null) === 'expired') {
                        $plan_end = '<span class="badge bg-danger">'.___('Expired').'</span> '
                            .'<small class="text-muted">'.date_formating($planEndAt).'</small>';
                    } else {
                        $plan_end = date_formating($planEndAt);
                    }
                } elseif (($row->plan_key ?? $row->group_id) === 'trial' && $row->created_at) {
                    // Trial user: derive from signup date + trial days
                    $trialEnd = strtotime($row->created_at) + ($trialDays * 86400);
                    if ($trialEnd < time()) {
                        $plan_end = '<span class="badge bg-danger">'.___('Expired').'</span> '
                            .'<small class="text-muted">'.date_formating($trialEnd).'</small>';
                    } else {
                        $plan_end = date_formating($trialEnd);
                    }
                } else {
                    $plan_end = '-';
                }

                $last_login = $row->lastactive ? date_formating($row->lastactive) : '-';

                // Raw plan end timestamp for WhatsApp message
                $trialDays = (int) ($row->trial_days ?? 7);
                $planEndTs = null;
                if ($planEndAt && $planEndAt > 0 && $planEndAt < 4000000000) {
                    $planEndTs = $planEndAt;
                } elseif (($row->plan_key ?? $row->group_id) === 'trial' && $row->created_at) {
                    $planEndTs = strtotime($row->created_at) + ($trialDays * 86400);
                }
                $isExpired = $planEndTs && $planEndTs < time();
                $planEndFormatted = $planEndTs ? date_formating($planEndTs) : '';

                // Build WhatsApp message (supports EN/AR)
                $locale = app()->getLocale();
                if ($locale === 'ar') {
                    $expireWord = $isExpired ? 'انتهى' : 'سينتهي';
                    $waMessage = "مرحباً {$row->name}، اشتراكك {$plan_label} {$expireWord} في {$planEndFormatted} يرجى تجديد اشتراكك، مع أطيب التحيات";
                } else {
                    $expireWord = $isExpired ? 'Expired' : 'will expire';
                    $waMessage = "Hi {$row->name}, your {$plan_label} {$expireWord} on {$planEndFormatted} please renew your subscription, best regards";
                }

                return $this->accountRow($row, $plan_label, $plan_start, $plan_end, $last_login, $waMessage);
            })->all();

            return response()->json([
                'draw' => (int) $request->input('draw'),
                'recordsTotal' => (int) $totalRecords,
                'recordsFiltered' => (int) $filteredRecords,
                'data' => $data,
            ], 200);
        }

        $freeTrialGroups = ['free', 'trial'];
        $userStats = [
            'total' => User::count(),
            /* R6: live-subscription based (legacy group_id counts included expired plans). */
            'paid' => app(Subscriptions::class)->whereLivePaid(User::where('user_type', 'user'))->count(),
            'free_trial' => app(Subscriptions::class)->whereLivePaid(User::where('user_type', 'user'), false)->count(),
            'new_30d' => User::where('created_at', '>=', now()->subDays(30))->count(),
        ];

        // Server-rendered list. applyUserFilters() reads both the flat ?search=
        // and the DataTables search[value] shape, so no request-bag rewrite is
        // needed here (rewriting would make request('search') an array and break
        // any view that echoes it — e.g. the global admin header).
        $listQuery = $this->usersTableQuery($request);

        $sortable = ['id', 'name', 'email', 'lastactive', 'created_at', 'stores_count', 'plan_end', 'transactions_count'];
        $sort = in_array($request->query('sort'), $sortable, true) ? $request->query('sort') : 'lastactive';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $listQuery->orderBy($sort, $dir)->orderByDesc('id');

        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;
        $users = $listQuery->paginate($perPage)->withQueryString();

        return view('admin.users.index', compact('plans', 'userStats', 'users', 'sort', 'dir'));
    }

    public function export(Request $request)
    {
        $users = $this->usersTableQuery($request);
        $this->applyUsersOrdering($users, $request);

        $plans = app(PlanCatalog::class)->plansByPosition();
        $filename = 'users-'.date('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($users, $plans) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                '#',
                ___('User'),
                ___('Email'),
                ___('Email status'),
                ___('Status'),
                ___('Subscription plan'),
                ___('Last login'),
                ___('Stores'),
                ___('Transactions'),
                ___('Joined'),
                ___('Country'),
            ],
                escape: '\\');

            $users->chunk(500, function ($chunk) use ($handle, $plans) {
                foreach ($chunk as $row) {
                    // name/username/email/country are user-registration input — neutralize
                    // spreadsheet formula injection when the superadmin opens the export.
                    fputcsv($handle, array_map([DatasetStreamWriter::class, 'csvCell'], [
                        $row->id,
                        $row->name.' (@'.$row->username.')',
                        $row->email,
                        $row->email_verified_at ? ___('Verified') : ___('Unverified'),
                        $row->status ? ___('Active') : ___('Banned'),
                        $this->userPlanLabel($row, $plans),
                        $row->lastactive ? date_formating($row->lastactive, 'Y-m-d H:i:s') : '',
                        (int) ($row->stores_count ?? 0),
                        (int) ($row->transactions_count ?? 0),
                        date_formating($row->created_at, 'Y-m-d H:i:s'),
                        $row->country,
                    ]),
                        escape: '\\');
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Export phone numbers and WhatsApp messages for bulk messaging.
     */
    public function whatsappExport(Request $request)
    {
        $users = $this->usersTableQuery($request);
        $this->applyUsersOrdering($users, $request);

        $plans = app(PlanCatalog::class)->plansByPosition();
        $lang = get_lang();
        $filename = 'whatsapp-export-'.date('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($users, $plans, $lang) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $lang === 'ar' ? [
                '#',
                'المستخدم',
                'البريد الإلكتروني',
                'الخطة',
                'حالة الاشتراك',
                'تاريخ الانتهاء',
                'رقم الهاتف',
                'رسالة واتساب',
                'واتساب الطلبات السريعة',
                'رسالة الطلبات السريعة',
            ] : [
                '#',
                'User',
                'Email',
                'Plan',
                'Plan Status',
                'Plan End',
                'Phone',
                'WhatsApp Message',
                'QuickOrder WhatsApp',
                'QuickOrder Message',
            ],
                escape: '\\');

            $users->chunk(500, function ($chunk) use ($handle, $plans, $lang) {
                foreach ($chunk as $row) {
                    // Plan label (same logic as userPlanLabel) — R3: subscription-derived key
                    $planKey = (string) ($row->plan_key ?? $row->group_id);
                    if ($planKey === 'free') {
                        $plan_label = $lang === 'ar' ? 'مجاني' : 'Free';
                    } elseif ($planKey === 'trial') {
                        $plan_label = $lang === 'ar' ? 'تجريبي' : 'Trial';
                    } else {
                        $plan = $plans->firstWhere('id', $planKey);
                        if ($plan) {
                            $plan_label = ! empty($plan->translations->{$lang}->name)
                                ? $plan->translations->{$lang}->name
                                : $plan->name;
                        } else {
                            $plan_label = $planKey;
                        }
                    }

                    // Plan end timestamp
                    $trialDays = (int) ($row->trial_days ?? 7);
                    $planEndTs = null;
                    if ($row->plan_end && $row->plan_end > 0 && $row->plan_end < 4000000000) {
                        $planEndTs = $row->plan_end;
                    } elseif (($row->plan_key ?? $row->group_id) === 'trial' && $row->created_at) {
                        $planEndTs = strtotime($row->created_at) + ($trialDays * 86400);
                    }

                    $isExpired = $planEndTs && $planEndTs < time();
                    $planEndFormatted = $planEndTs ? date_formating($planEndTs) : '';

                    // Build WhatsApp message (EN/AR)
                    if ($lang === 'ar') {
                        $expireWord = $isExpired ? 'انتهى' : 'سينتهي';
                        $planStatus = $isExpired ? 'منتهي' : 'نشط';
                        $waMessage = "مرحباً {$row->name}، اشتراكك {$plan_label} {$expireWord} في {$planEndFormatted} يرجى تجديد اشتراكك، مع أطيب التحيات";
                    } else {
                        $expireWord = $isExpired ? 'Expired' : 'will expire';
                        $planStatus = $isExpired ? 'Expired' : 'Active';
                        $waMessage = "Hi {$row->name}, your {$plan_label} {$expireWord} on {$planEndFormatted} please renew your subscription, best regards";
                    }

                    // Phone number cleanup
                    $storePhone = $row->store_phone ?? '';
                    $storePhoneClean = $storePhone ? preg_replace('/[^0-9]/', '', $storePhone) : '';
                    $storePhoneClean = $storePhoneClean ? "\t".$storePhoneClean : '';

                    // Branch WhatsApp (normalize handles Arabic-Indic numerals;
                    // a bare \D strip would delete them)
                    $qoPhone = $row->branch_whatsapp ?? '';
                    $qoPhoneClean = $qoPhone ? whatsapp_normalize_number($qoPhone) : '';
                    $qoPhoneClean = $qoPhoneClean ? "\t".$qoPhoneClean : '';
                    $qoMessage = '';
                    if ($lang === 'ar') {
                        $qoMessage = "مرحباً {$row->name}، طلبك من {$plan_label} جاهز، مع أطيب التحيات";
                    } else {
                        $qoMessage = "Hi {$row->name}, your order from {$plan_label} is ready, best regards";
                    }

                    fputcsv($handle, array_map([DatasetStreamWriter::class, 'csvCell'], [
                        $row->id,
                        $row->name.' (@'.$row->username.')',
                        $row->email,
                        $plan_label,
                        $planStatus,
                        $planEndFormatted,
                        $storePhoneClean,
                        $waMessage,
                        $qoPhoneClean,
                        $qoMessage,
                    ]),
                        escape: '\\');
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function fullExport(User $user)
    {
        $backup = $this->buildFullBackupPayload($user);
        $filename = 'user-'.$user->id.'-full-backup-'.now()->format('Ymd-His').'.zip';
        $tempDir = $this->backupTempDirectory();
        $zipPath = $tempDir.DIRECTORY_SEPARATOR.Str::random(24).'.zip';

        if (! class_exists(\ZipArchive::class)) {
            abort(500, 'ZipArchive extension is required for user backups.');
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Unable to create backup archive.');
        }

        $zip->addFromString(
            'manifest.json',
            json_encode($backup['manifest'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        foreach ($backup['files'] as $archivePath => $sourcePath) {
            if (is_file($sourcePath)) {
                $zip->addFile($sourcePath, $archivePath);
            }
        }

        $zip->close();

        return response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function hungerinoExport(User $user)
    {
        $backup = $this->buildFullBackupPayload($user);
        $targetPackage = $this->buildSourceTargetExportPackage($user, $backup['manifest'], $backup['files']);
        $filename = 'user-'.$user->id.'-hungerino-export-'.now()->format('Ymd-His').'.zip';
        $tempDir = $this->backupTempDirectory().DIRECTORY_SEPARATOR.'hungerino-export-'.Str::random(24);
        $zipPath = $this->backupTempDirectory().DIRECTORY_SEPARATOR.'hungerino-export-'.Str::random(24).'.zip';
        $zip = null;
        $exportFailed = false;

        if (! is_dir($tempDir) && ! mkdir($tempDir, 0775, true) && ! is_dir($tempDir)) {
            abort(500, 'Unable to create export working directory.');
        }

        if (! class_exists(\ZipArchive::class)) {
            abort(500, 'ZipArchive extension is required for user backups.');
        }

        foreach ($targetPackage['json'] as $fileName => $contents) {
            $fullPath = $tempDir.DIRECTORY_SEPARATOR.$fileName;
            $directory = dirname($fullPath);
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                abort(500, 'Unable to prepare export package.');
            }

            file_put_contents($fullPath, json_encode($contents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }

        foreach ($targetPackage['files'] as $fileName => $sourcePath) {
            $fullPath = $tempDir.DIRECTORY_SEPARATOR.$fileName;
            $directory = dirname($fullPath);
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                abort(500, 'Unable to prepare export assets.');
            }

            if (is_file($sourcePath)) {
                copy($sourcePath, $fullPath);
            }
        }

        try {
            $zip = new \ZipArchive;
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                abort(500, 'Unable to create export archive.');
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $relativePath = substr($file->getPathname(), strlen($tempDir) + 1);
                    $zip->addFile($file->getPathname(), $relativePath);
                }
            }

            $zip->close();
        } catch (\Throwable $e) {
            $exportFailed = true;
            throw $e;
        } finally {
            $this->removeDirectory($tempDir);
            if ($exportFailed && is_file($zipPath)) {
                @unlink($zipPath);
            }
        }

        return response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function fullImport(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'backup_file' => ['required', 'file', 'mimes:zip', 'max:102400'],
        ]);

        if ($validator->fails()) {
            app_alert_error(implode('<br>', $validator->errors()->all()));

            return back();
        }

        if (! class_exists(\ZipArchive::class)) {
            app_alert_error('ZipArchive extension is required for user backups.');

            return back();
        }

        $uploadedFile = $request->file('backup_file');
        $extractDir = $this->backupTempDirectory().DIRECTORY_SEPARATOR.'extract-'.Str::random(24);
        $createdFiles = [];

        if (! is_dir($extractDir) && ! mkdir($extractDir, 0775, true) && ! is_dir($extractDir)) {
            app_alert_error('Unable to prepare backup extraction directory.');

            return back();
        }

        $zip = new \ZipArchive;
        $opened = $zip->open($uploadedFile->getRealPath());
        if ($opened !== true) {
            $this->removeDirectory($extractDir);
            app_alert_error('Unable to open the uploaded backup file.');

            return back();
        }

        try {
            BackupZipValidator::assertSafe($zip);
        } catch (\RuntimeException $e) {
            $zip->close();
            $this->removeDirectory($extractDir);
            app_alert_error($e->getMessage());

            return back();
        }

        if (! $zip->extractTo($extractDir)) {
            $zip->close();
            $this->removeDirectory($extractDir);
            app_alert_error(___('Unable to extract the uploaded backup file.'));

            return back();
        }

        $zip->close();

        $manifestPath = $extractDir.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($manifestPath)) {
            $this->removeDirectory($extractDir);
            app_alert_error('Backup archive is missing manifest.json.');

            return back();
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest) || empty($manifest['user']) || ! is_array($manifest['user'])) {
            $this->removeDirectory($extractDir);
            app_alert_error('Backup archive is invalid.');

            return back();
        }

        $summary = $this->summarizeBackupManifest($manifest);
        $reportPath = $this->writeImportReport($user, $manifest, [
            'dry_run' => $request->boolean('dry_run'),
            'validated_at' => now()->toDateTimeString(),
        ]);
        $reportLink = route('admin.users.fullImportReport', [$user->id, basename($reportPath)]);
        $summaryWithReport = $summary.'<br><a href="'.e($reportLink).'" target="_blank" rel="noopener">'.e(___('Download import report')).'</a>';
        if ($request->boolean('dry_run')) {
            // C3: real dry-run — persist the archive, show per-table delete/restore
            // counts, and let the operator confirm. Nothing is written here.
            $token = Str::lower(Str::random(40));
            $request->file('backup_file')->storeAs('admin-imports/'.$user->id, $token.'.zip');
            $view = view('admin.users.import-preview', [
                'user' => $user,
                'token' => $token,
                'plan' => $this->buildRestorePlan($user, $manifest),
                'manifestSummary' => $summaryWithReport,
                'complete' => $this->manifestIsRegistryComplete($manifest),
            ])->render();
            $this->removeDirectory($extractDir);

            return response($view);
        }

        try {
            DB::transaction(function () use ($user, $manifest, $extractDir, &$createdFiles, $request) {
                $this->restoreFullBackup($user, $manifest, $extractDir, $createdFiles, $request->boolean('allow_partial_restore'));
            });
        } catch (\Throwable $e) {
            foreach ($createdFiles as $createdFile) {
                remove_file($createdFile);
            }
            $this->removeDirectory($extractDir);
            report($e);
            app_alert_error(___('Import failed. Check the file and try again.'));

            return back();
        }

        $this->removeDirectory($extractDir);
        app_alert_success(___('Imported Successfully').'<br>'.$summaryWithReport);

        return back();
    }

    public function fullImportReport(User $user, string $reportFile)
    {
        $reportFile = basename($reportFile);
        $reportPath = $this->backupReportsDirectory().DIRECTORY_SEPARATOR.$reportFile;

        if (! is_file($reportPath)) {
            abort(404);
        }

        return response()->download($reportPath, $reportFile, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function bulkFullExport(Request $request)
    {
        $query = $this->usersTableQuery($request);
        $this->applyUsersOrdering($query, $request);
        $userIds = (clone $query)->pluck('id')->map(fn ($id) => (int) $id)->values();

        if ($userIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => ___('No users found'),
            ], 422);
        }

        $token = Str::random(32);
        $exportDir = $this->bulkExportDirectory($token);
        if (! is_dir($exportDir) && ! mkdir($exportDir, 0775, true) && ! is_dir($exportDir)) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to prepare export directory.',
            ], 500);
        }

        file_put_contents($exportDir.DIRECTORY_SEPARATOR.'request.json', json_encode([
            'created_at' => now()->toDateTimeString(),
            'user_ids' => $userIds->all(),
            'filters' => $request->only(['plan', 'email_status', 'status', 'restaurants', 'search']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        file_put_contents($exportDir.DIRECTORY_SEPARATOR.'status.json', json_encode([
            'state' => 'processing',
            'started_at' => now()->toDateTimeString(),
            'current' => 0,
            'total' => count($userIds),
            'message' => 'Preparing export package',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $this->startBackgroundArtisan('users:generate-full-export-package', $token);

        return response()->json([
            'success' => true,
            'message' => ___('Full export started. You can keep working while it prepares the ZIP package.'),
            'token' => $token,
            'status_url' => route('admin.users.fullExportPackageStatus', $token),
        ], 200);
    }

    /**
     * Run an artisan export-package command in the background. P6-140: on a shared host proc_open is disabled (and
     * PHP_BINDIR/php is often the wrong binary), so `Process::start()` would 500 — there the command runs in-process
     * after the response is sent; the status file the page polls works the same either way.
     */
    private function startBackgroundArtisan(string $command, string $token): void
    {
        if (! app(HostCapabilities::class)->processesAllowed()) {
            dispatch(static fn () => Artisan::call($command, ['token' => $token]))->afterResponse();

            return;
        }

        $phpBinary = PHP_BINDIR.DIRECTORY_SEPARATOR.'php';
        $process = Process::fromShellCommandline($phpBinary.' '.escapeshellarg(base_path('artisan')).' '.$command.' '.escapeshellarg($token), base_path());
        $process->setTimeout(null);
        $process->start();
    }

    public function bulkFullExportStatus(string $token)
    {
        $statusPath = $this->bulkExportDirectory($token).DIRECTORY_SEPARATOR.'status.json';
        if (! is_file($statusPath)) {
            return response()->json([
                'success' => false,
                'state' => 'missing',
                'message' => 'Export job not found.',
            ], 404);
        }

        $status = json_decode((string) file_get_contents($statusPath), true) ?: [];
        $state = $status['state'] ?? 'processing';
        $response = [
            'success' => true,
            'state' => $state,
            'message' => $status['message'] ?? '',
        ];

        if ($state === 'ready') {
            $response['download_url'] = route('admin.users.fullExportPackageDownload', $token);
        }

        return response()->json($response, 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function bulkFullExportDownload(string $token)
    {
        $zipPath = $this->bulkExportDirectory($token).DIRECTORY_SEPARATOR.'package.zip';
        if (! is_file($zipPath)) {
            abort(404);
        }

        return response()->download($zipPath, 'users-full-backup-'.$token.'.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function bulkTargetExport(Request $request)
    {
        $query = $this->usersTableQuery($request);
        $this->applyUsersOrdering($query, $request);
        $userIds = (clone $query)->pluck('id')->map(fn ($id) => (int) $id)->values();

        if ($userIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => ___('No users found'),
            ], 422);
        }

        $token = Str::random(32);
        $exportDir = $this->targetExportDirectory($token);
        if (! is_dir($exportDir) && ! mkdir($exportDir, 0775, true) && ! is_dir($exportDir)) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to prepare export directory.',
            ], 500);
        }

        file_put_contents($exportDir.DIRECTORY_SEPARATOR.'request.json', json_encode([
            'created_at' => now()->toDateTimeString(),
            'user_ids' => $userIds->all(),
            'filters' => $request->only(['plan', 'email_status', 'status', 'restaurants', 'search']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        file_put_contents($exportDir.DIRECTORY_SEPARATOR.'status.json', json_encode([
            'state' => 'processing',
            'started_at' => now()->toDateTimeString(),
            'current' => 0,
            'total' => count($userIds),
            'message' => 'Preparing target export package',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $this->startBackgroundArtisan('users:generate-target-export-package', $token);

        return response()->json([
            'success' => true,
            'message' => ___('Target export started. You can keep working while it prepares the Hungerino package.'),
            'token' => $token,
            'status_url' => route('admin.users.targetExportPackageStatus', $token),
        ], 200);
    }

    public function bulkTargetExportStatus(string $token)
    {
        $statusPath = $this->targetExportDirectory($token).DIRECTORY_SEPARATOR.'status.json';
        if (! is_file($statusPath)) {
            return response()->json([
                'success' => false,
                'state' => 'missing',
                'message' => 'Export job not found.',
            ], 404);
        }

        $status = json_decode((string) file_get_contents($statusPath), true) ?: [];
        $state = $status['state'] ?? 'processing';
        $response = [
            'success' => true,
            'state' => $state,
            'message' => $status['message'] ?? '',
        ];

        if ($state === 'ready') {
            $response['download_url'] = route('admin.users.targetExportPackageDownload', $token);
        }

        return response()->json($response, 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function bulkTargetExportDownload(string $token)
    {
        $zipPath = $this->targetExportDirectory($token).DIRECTORY_SEPARATOR.'package.zip';
        if (! is_file($zipPath)) {
            abort(404);
        }

        return response()->download($zipPath, 'users-target-export-'.$token.'.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function bulkHungerinoExport(Request $request)
    {
        $query = $this->usersTableQuery($request);
        $this->applyUsersOrdering($query, $request);
        $userIds = (clone $query)->pluck('id')->map(fn ($id) => (int) $id)->values();

        if ($userIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => ___('No users found'),
            ], 422);
        }

        $token = Str::random(32);
        $exportDir = $this->hungerinoExportDirectory($token);
        if (! is_dir($exportDir) && ! mkdir($exportDir, 0775, true) && ! is_dir($exportDir)) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to prepare export directory.',
            ], 500);
        }

        file_put_contents($exportDir.DIRECTORY_SEPARATOR.'request.json', json_encode([
            'created_at' => now()->toDateTimeString(),
            'user_ids' => $userIds->all(),
            'filters' => $request->only(['plan', 'email_status', 'status', 'restaurants', 'search']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        file_put_contents($exportDir.DIRECTORY_SEPARATOR.'status.json', json_encode([
            'state' => 'processing',
            'started_at' => now()->toDateTimeString(),
            'current' => 0,
            'total' => count($userIds),
            'message' => 'Preparing Hungerino export package',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $this->startBackgroundArtisan('users:generate-hungerino-export-package', $token);

        return response()->json([
            'success' => true,
            'message' => ___('Hungerino export started. You can keep working while it prepares the ZIP package.'),
            'token' => $token,
            'status_url' => route('admin.users.hungerinoExportPackageStatus', $token),
        ], 200);
    }

    public function bulkHungerinoExportStatus(string $token)
    {
        $statusPath = $this->hungerinoExportDirectory($token).DIRECTORY_SEPARATOR.'status.json';
        if (! is_file($statusPath)) {
            return response()->json([
                'success' => false,
                'state' => 'missing',
                'message' => 'Export job not found.',
            ], 404);
        }

        $status = json_decode((string) file_get_contents($statusPath), true) ?: [];
        $state = $status['state'] ?? 'processing';
        $response = [
            'success' => true,
            'state' => $state,
            'message' => $status['message'] ?? '',
        ];

        if ($state === 'ready') {
            $response['download_url'] = route('admin.users.hungerinoExportPackageDownload', $token);
        }

        return response()->json($response, 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function bulkHungerinoExportDownload(string $token)
    {
        $zipPath = $this->hungerinoExportDirectory($token).DIRECTORY_SEPARATOR.'package.zip';
        if (! is_file($zipPath)) {
            abort(404);
        }

        return response()->download($zipPath, 'users-hungerino-export-'.$token.'.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function generateBulkFullExportPackage(string $token): void
    {
        $exportDir = $this->bulkExportDirectory($token);
        $requestPath = $exportDir.DIRECTORY_SEPARATOR.'request.json';
        $statusPath = $exportDir.DIRECTORY_SEPARATOR.'status.json';
        $zipPath = $exportDir.DIRECTORY_SEPARATOR.'package.zip';
        $current = 0;
        $total = 0;

        try {
            if (! is_file($requestPath)) {
                throw new \RuntimeException('Export request is missing.');
            }

            $requestData = json_decode((string) file_get_contents($requestPath), true) ?: [];
            $userIds = collect($requestData['user_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
            $total = $userIds->count();

            if ($userIds->isEmpty()) {
                throw new \RuntimeException('No users were found for export.');
            }

            if (! class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('ZipArchive extension is required for user backups.');
            }

            $zip = new \ZipArchive;
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create backup archive.');
            }

            $index = [];
            foreach ($userIds as $userId) {
                $current++;
                $user = User::find($userId);
                if (! $user) {
                    $this->writeBulkExportStatus($token, [
                        'state' => 'processing',
                        'message' => 'Skipping missing user '.$userId,
                        'current' => $current,
                        'total' => $total,
                    ]);

                    continue;
                }

                $this->writeBulkExportStatus($token, [
                    'state' => 'processing',
                    'message' => 'Exporting '.($user->name ?: $user->username ?: ('user #'.$user->id)).' ('.$current.'/'.$total.')',
                    'current' => $current,
                    'total' => $total,
                ]);

                $backup = $this->buildFullBackupPayload($user);
                $userFolder = 'users/'.$user->id.'-'.Str::slug($user->username ?: $user->name ?: ('user-'.$user->id));
                $zip->addFromString(
                    $userFolder.'/manifest.json',
                    json_encode($backup['manifest'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                );

                foreach ($backup['files'] as $archivePath => $sourcePath) {
                    if (is_file($sourcePath)) {
                        $zip->addFile($sourcePath, $userFolder.'/'.$archivePath);
                    }
                }

                $index[] = [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                ];
            }

            $this->writeBulkExportStatus($token, [
                'state' => 'finalizing',
                'message' => 'Finalizing archive',
                'current' => $current,
                'total' => $total,
            ]);

            $zip->addFromString(
                'index.json',
                json_encode([
                    'version' => 1,
                    'exported_at' => now()->toDateTimeString(),
                    'count' => count($index),
                    'users' => $index,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );

            if ($zip->close() !== true) {
                throw new \RuntimeException('Unable to finalize backup archive.');
            }

            $this->writeBulkExportStatus($token, [
                'state' => 'ready',
                'message' => 'Export complete',
                'current' => $total,
                'total' => $total,
                'finished_at' => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            $this->writeBulkExportStatus($token, [
                'state' => 'failed',
                'message' => $e->getMessage(),
                'current' => $current,
                'total' => $total,
            ]);

            throw $e;
        }
    }

    public function generateBulkTargetExportPackage(string $token): void
    {
        $exportDir = $this->targetExportDirectory($token);
        $requestPath = $exportDir.DIRECTORY_SEPARATOR.'request.json';
        $statusPath = $exportDir.DIRECTORY_SEPARATOR.'status.json';
        $zipPath = $exportDir.DIRECTORY_SEPARATOR.'package.zip';
        $current = 0;
        $total = 0;

        try {
            if (! is_file($requestPath)) {
                throw new \RuntimeException('Export request is missing.');
            }

            $requestData = json_decode((string) file_get_contents($requestPath), true) ?: [];
            $userIds = collect($requestData['user_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
            $total = $userIds->count();

            if ($userIds->isEmpty()) {
                throw new \RuntimeException('No users were found for export.');
            }

            if (! class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('ZipArchive extension is required for user backups.');
            }

            $zip = new \ZipArchive;
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create backup archive.');
            }

            $index = [];
            foreach ($userIds as $userId) {
                $current++;
                $user = User::find($userId);
                if (! $user) {
                    $this->writeExportStatusToDirectory($exportDir, [
                        'state' => 'processing',
                        'message' => 'Skipping missing user '.$userId,
                        'current' => $current,
                        'total' => $total,
                    ]);

                    continue;
                }

                $this->writeExportStatusToDirectory($exportDir, [
                    'state' => 'processing',
                    'message' => 'Exporting '.($user->name ?: $user->username ?: ('user #'.$user->id)).' ('.$current.'/'.$total.')',
                    'current' => $current,
                    'total' => $total,
                ]);

                $backup = $this->buildTargetMigrationPayload($user);
                $userFolder = 'users/'.$user->id.'-'.Str::slug($user->username ?: $user->name ?: ('user-'.$user->id));
                $zip->addFromString(
                    $userFolder.'/manifest.json',
                    json_encode($backup['manifest'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                );

                foreach ($backup['files'] as $archivePath => $sourcePath) {
                    if (is_file($sourcePath)) {
                        $zip->addFile($sourcePath, $userFolder.'/'.$archivePath);
                    }
                }

                $index[] = [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                ];
            }

            $this->writeExportStatusToDirectory($exportDir, [
                'state' => 'finalizing',
                'message' => 'Finalizing archive',
                'current' => $current,
                'total' => $total,
            ]);

            $zip->addFromString(
                'index.json',
                json_encode([
                    'version' => 1,
                    'exported_at' => now()->toDateTimeString(),
                    'count' => count($index),
                    'users' => $index,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );

            if ($zip->close() !== true) {
                throw new \RuntimeException('Unable to finalize backup archive.');
            }

            $this->writeExportStatusToDirectory($exportDir, [
                'state' => 'ready',
                'message' => 'Target export complete',
                'current' => $total,
                'total' => $total,
                'finished_at' => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            $this->writeExportStatusToDirectory($exportDir, [
                'state' => 'failed',
                'message' => $e->getMessage(),
                'current' => $current,
                'total' => $total,
            ]);

            throw $e;
        }
    }

    public function generateBulkHungerinoExportPackage(string $token): void
    {
        $exportDir = $this->hungerinoExportDirectory($token);
        $requestPath = $exportDir.DIRECTORY_SEPARATOR.'request.json';
        $statusPath = $exportDir.DIRECTORY_SEPARATOR.'status.json';
        $zipPath = $exportDir.DIRECTORY_SEPARATOR.'package.zip';
        $current = 0;
        $total = 0;

        try {
            if (! is_file($requestPath)) {
                throw new \RuntimeException('Export request is missing.');
            }

            $requestData = json_decode((string) file_get_contents($requestPath), true) ?: [];
            $userIds = collect($requestData['user_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
            $total = $userIds->count();

            if ($userIds->isEmpty()) {
                throw new \RuntimeException('No users were found for export.');
            }

            if (! class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('ZipArchive extension is required for user backups.');
            }

            $zip = new \ZipArchive;
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create backup archive.');
            }

            $index = [];
            foreach ($userIds as $userId) {
                $current++;
                $user = User::find($userId);
                if (! $user) {
                    $this->writeExportStatusToDirectory($exportDir, [
                        'state' => 'processing',
                        'message' => 'Skipping missing user '.$userId,
                        'current' => $current,
                        'total' => $total,
                    ]);

                    continue;
                }

                $this->writeExportStatusToDirectory($exportDir, [
                    'state' => 'processing',
                    'message' => 'Exporting '.($user->name ?: $user->username ?: ('user #'.$user->id)).' ('.$current.'/'.$total.')',
                    'current' => $current,
                    'total' => $total,
                ]);

                $backup = $this->buildFullBackupPayload($user);
                $package = $this->buildSourceTargetExportPackage($user, $backup['manifest'], $backup['files']);
                $userFolder = 'users/'.$user->id.'-'.Str::slug($user->username ?: $user->name ?: ('user-'.$user->id));

                foreach ($package['json'] as $fileName => $contents) {
                    $zip->addFromString(
                        $userFolder.'/'.$fileName,
                        json_encode($contents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                    );
                }

                foreach ($package['files'] as $archivePath => $sourcePath) {
                    if (is_file($sourcePath)) {
                        $zip->addFile($sourcePath, $userFolder.'/'.$archivePath);
                    }
                }

                $index[] = [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                ];
            }

            $this->writeExportStatusToDirectory($exportDir, [
                'state' => 'finalizing',
                'message' => 'Finalizing archive',
                'current' => $current,
                'total' => $total,
            ]);

            $zip->addFromString(
                'index.json',
                json_encode([
                    'version' => 1,
                    'exported_at' => now()->toDateTimeString(),
                    'count' => count($index),
                    'users' => $index,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );

            if ($zip->close() !== true) {
                throw new \RuntimeException('Unable to finalize backup archive.');
            }

            $this->writeExportStatusToDirectory($exportDir, [
                'state' => 'ready',
                'message' => 'Export complete',
                'current' => $total,
                'total' => $total,
                'finished_at' => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            $this->writeExportStatusToDirectory($exportDir, [
                'state' => 'failed',
                'message' => $e->getMessage(),
                'current' => $current,
                'total' => $total,
            ]);

            throw $e;
        }
    }

    public function bulkFullImport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'backup_file' => ['required', 'file', 'mimes:zip', 'max:102400'],
        ]);

        if ($validator->fails()) {
            app_alert_error(implode('<br>', $validator->errors()->all()));

            return back();
        }

        if (! class_exists(\ZipArchive::class)) {
            app_alert_error('ZipArchive extension is required for user backups.');

            return back();
        }

        $extractDir = $this->backupTempDirectory().DIRECTORY_SEPARATOR.'bulk-extract-'.Str::random(24);
        if (! is_dir($extractDir) && ! mkdir($extractDir, 0775, true) && ! is_dir($extractDir)) {
            app_alert_error('Unable to prepare backup extraction directory.');

            return back();
        }

        $zip = new \ZipArchive;
        $opened = $zip->open($request->file('backup_file')->getRealPath());
        if ($opened !== true) {
            $this->removeDirectory($extractDir);
            app_alert_error('Unable to open the uploaded backup file.');

            return back();
        }

        try {
            BackupZipValidator::assertSafe($zip);
        } catch (\RuntimeException $e) {
            $zip->close();
            $this->removeDirectory($extractDir);
            app_alert_error($e->getMessage());

            return back();
        }

        if (! $zip->extractTo($extractDir)) {
            $zip->close();
            $this->removeDirectory($extractDir);
            app_alert_error(___('Unable to extract the uploaded backup file.'));

            return back();
        }

        $zip->close();

        $manifestFiles = collect(glob($extractDir.DIRECTORY_SEPARATOR.'users'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'manifest.json') ?: []);
        if ($manifestFiles->isEmpty()) {
            $singleManifest = $extractDir.DIRECTORY_SEPARATOR.'manifest.json';
            if (is_file($singleManifest)) {
                $manifestFiles = collect([$singleManifest]);
            }
        }

        if ($manifestFiles->isEmpty()) {
            $this->removeDirectory($extractDir);
            app_alert_error('No user manifests found in the backup package.');

            return back();
        }

        $results = [
            'imported' => 0,
            'created' => 0,
            'skipped' => 0,
            'messages' => [],
        ];

        $previewLines = [];

        foreach ($manifestFiles as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest) || empty($manifest['user']) || ! is_array($manifest['user'])) {
                $results['skipped']++;
                $results['messages'][] = basename(dirname($manifestPath)).': invalid manifest';

                continue;
            }

            $targetUser = $this->findTargetUserForManifest($manifest['user']);
            $createdUser = false;
            if (! $targetUser) {
                if ($request->boolean('dry_run')) {
                    $previewLines[] = $this->summarizeBackupManifest($manifest).'<br>User action: would create missing user';
                    $results['imported']++;

                    continue;
                }

                $targetUser = $this->createUserFromManifest($manifest['user']);
                $createdUser = true;
                $results['created']++;
            }

            $previewLines[] = $this->summarizeBackupManifest($manifest);
            if ($request->boolean('dry_run')) {
                $results['imported']++;

                continue;
            }

            try {
                DB::transaction(function () use ($targetUser, $manifestPath, &$results, $request) {
                    $manifest = json_decode((string) file_get_contents($manifestPath), true);
                    $baseDir = dirname($manifestPath);
                    $createdFiles = [];
                    $this->restoreFullBackup($targetUser, $manifest, $baseDir, $createdFiles, $request->boolean('allow_partial_restore'));
                    $results['imported']++;
                });
            } catch (\Throwable $e) {
                $this->removeDirectory($extractDir);
                report($e);
                app_alert_error(___('Bulk import failed. Check the file and try again.'));

                return back();
            }

            if ($createdUser) {
                $previewLines[] = 'Created user: '.e($targetUser->email);
            }
        }

        $this->removeDirectory($extractDir);
        $summary = 'Imported: '.number_format($results['imported']).'<br>Created: '.number_format($results['created']).'<br>Skipped: '.number_format($results['skipped']);
        if ($request->boolean('dry_run')) {
            $summary = 'Dry run completed.<br>'.$summary;
        }
        if (! empty($results['messages'])) {
            $summary .= '<br>'.implode('<br>', array_map('e', $results['messages']));
        }
        if (! empty($previewLines)) {
            $summary .= '<br>'.implode('<br><br>', array_map('e', $previewLines));
        }

        $reportPath = $this->writeBulkImportReport([
            'dry_run' => $request->boolean('dry_run'),
            'generated_at' => now()->toDateTimeString(),
            'summary' => $summary,
            'results' => $results,
            'preview' => $previewLines,
        ]);
        $reportLink = route('admin.users.fullImportPackageReport', basename($reportPath));
        app_alert_success($summary.'<br><a href="'.e($reportLink).'" target="_blank" rel="noopener">'.e(___('Download import report')).'</a>');

        return back();
    }

    public function bulkFullImportReport(string $reportFile)
    {
        $reportFile = basename($reportFile);
        $reportPath = $this->backupReportsDirectory().DIRECTORY_SEPARATOR.$reportFile;

        if (! is_file($reportPath)) {
            abort(404);
        }

        return response()->download($reportPath, $reportFile, [
            'Content-Type' => 'application/json',
        ]);
    }

    protected function buildFullBackupPayload(User $user): array
    {
        $plans = $this->subscriptionPlans();

        $registryEngine = config('settings.admin_backup_registry_engine') === '1';

        $manifest = [
            'version' => 1,
            // C3: schema 2 = registry-complete (every store table, id-preserving
            // restore). The restore reader keeps schema-1 support for old archives.
            'schema' => $registryEngine ? 2 : 1,
            'registry_tables' => $registryEngine
                ? array_keys(StoreBackupService::tableRegistry())
                : [],
            'exported_at' => now()->toDateTimeString(),
            'user' => $this->exportUserRecord($user),
            'stores' => [],
            'blogs' => [],
            'blog_categories' => [],
        ];

        $files = [];

        $manifest['user']['subscription'] = $this->exportSubscriptionSnapshot($user, $plans);

        $userImage = $this->publicStoragePath('profile/'.$user->image);
        if ($this->shouldIncludeImage($user->image) && is_file($userImage)) {
            $files['files/profile/'.$user->image] = $userImage;
        }

        $manifest['user']['options'] = UserSettings::legacyRows((int) $user->id); // P1-07: users.preferences + accounts.billing_profile as legacy rows

        $manifest['user']['upgrades'] = DB::table('upgrades')
            ->where('user_id', $user->id)
            ->orderBy('upgrade_id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $manifest['user']['transactions'] = DB::table('transaction')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $manifest['user']['blog_comments'] = DB::table('blog_comments')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $blogCategoryMap = [];
        $blogs = Blog::with('categories')
            ->where('author', $user->id)
            ->orderBy('id')
            ->get();

        foreach ($blogs as $blog) {
            $blogRow = (array) DB::table('blog_posts')->where('id', $blog->id)->first();
            if (empty($blogRow)) {
                continue;
            }

            if (! empty($blogRow['image']) && $this->shouldIncludeImage($blogRow['image'])) {
                $sourcePath = $this->publicStoragePath('blog/'.$blogRow['image']);
                if (is_file($sourcePath)) {
                    $files['files/blog/'.$blogRow['image']] = $sourcePath;
                }
            }

            $categoryIds = [];
            foreach ($blog->categories as $category) {
                if (empty($blogCategoryMap[$category->id])) {
                    $categoryRow = (array) DB::table('blog_categories')->where('id', $category->id)->first();
                    if (! empty($categoryRow)) {
                        $blogCategoryMap[$category->id] = $categoryRow;
                    }
                }

                if (! empty($blogCategoryMap[$category->id])) {
                    $categoryIds[] = $category->id;
                }
            }

            $manifest['blogs'][] = [
                'post' => $blogRow,
                'category_ids' => $categoryIds,
            ];
        }

        foreach ($blogCategoryMap as $categoryRow) {
            $manifest['blog_categories'][] = $categoryRow;
        }

        $stores = DB::table('stores')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        foreach ($stores as $restaurantRow) {
            $store = (array) $restaurantRow;
            $storeId = (int) $store['id'];

            foreach (['main_image' => 'store/logo/', 'cover_image' => 'store/cover/', 'bio_main_image' => 'store/logo/', 'bio_cover_image' => 'store/cover/'] as $field => $folder) {
                if (! empty($store[$field]) && $this->shouldIncludeImage($store[$field])) {
                    $sourcePath = $this->publicStoragePath($folder.$store[$field]);
                    if (is_file($sourcePath)) {
                        $files['files/'.$folder.$store[$field]] = $sourcePath;
                    }
                }
            }

            // C3: registry engine (dark, admin_backup_registry_engine) — a
            // registry-complete export covering all 68 store tables id-preservingly.
            // Off = the legacy 10 sections below, unchanged. Image-file bundling
            // above/below is schema-agnostic (image_menus is a registry key too).
            $restaurantBackup = config('settings.admin_backup_registry_engine') === '1'
                ? ['store' => $store] + app(AdminStoreArchive::class)->export(Store::find($storeId))
                : [
                    'store' => $store,
                    'store_options' => array_merge(StoreSettings::legacyRows((int) $storeId), StorePaymentGateways::legacyRows((int) $storeId)), // P1-07: stores.settings + gateway config, no credentials
                    'tables' => DB::table('store_tables')
                        ->where('store_id', $storeId)
                        ->orderBy('id')
                        ->get()
                        ->map(fn ($row) => (array) $row)
                        ->all(),
                    'image_menus' => DB::table('image_menus')
                        ->where('store_id', $storeId)
                        ->orderBy('position')
                        ->orderBy('id')
                        ->get()
                        ->map(fn ($row) => (array) $row)
                        ->all(),
                    'categories' => DB::table('categories')
                        ->where('store_id', $storeId)
                        ->orderBy('position')
                        ->orderBy('id')
                        ->get()
                        ->map(fn ($row) => (array) $row)
                        ->all(),
                    'menus' => DB::table('items')
                        ->where('store_id', $storeId)
                        ->whereNull('deleted_at') // back up live rows only, not soft-deleted tombstones
                        ->orderBy('position')
                        ->orderBy('id')
                        ->get()
                        ->map(fn ($row) => (array) $row)
                        ->all(),
                    // P1-06: add-ons are `modifiers` rows now; the archive keeps the legacy menu_extras row shape.
                    'menu_extras' => DB::table('modifiers')
                        ->where('kind', 'addon')
                        ->whereIn('menu_id', $this->extractIds(DB::table('items')
                            ->where('store_id', $storeId)
                            ->whereNull('deleted_at')
                            ->pluck('id')))
                        ->orderBy('position')
                        ->orderBy('id')
                        ->get()
                        ->map(fn ($row) => Arr::except((array) $row, ['public_id', 'modifier_group_id', 'kind']))
                        ->all(),
                    'menu_variants' => DB::table('menu_variants')
                        ->whereIn('menu_id', $this->extractIds(DB::table('items')
                            ->where('store_id', $storeId)
                            ->whereNull('deleted_at')
                            ->pluck('id')))
                        ->orderBy('position')
                        ->orderBy('id')
                        ->get()
                        ->map(fn ($row) => (array) $row)
                        ->all(),
                    // P1-06: option groups are `modifier_groups` (kind variant) + one modifier per option; the archive keeps
                    // the legacy menu_variant_options row shape (options / option_prices / option_images JSON lists).
                    'menu_variant_options' => MenuVariantOption::query()
                        ->whereIn('menu_id', $this->extractIds(DB::table('items')
                            ->where('store_id', $storeId)
                            ->whereNull('deleted_at')
                            ->pluck('id')))
                        ->orderBy('position')
                        ->orderBy('id')
                        ->get()
                        ->map(fn (MenuVariantOption $group) => Arr::except($group->getAttributes(), ['public_id', 'kind']) + [
                            'options' => json_encode($group->baseList('options')),
                            'option_prices' => ($prices = $group->baseList('option_prices')) === null ? null : json_encode($prices),
                            'option_images' => ($images = $group->baseList('option_images')) === null ? null : json_encode($images),
                        ])
                        ->all(),
                    'orders' => $this->exportRestaurantOrders($storeId),
                ];

            foreach ($restaurantBackup['image_menus'] ?? [] as $imageMenu) {
                if (! empty($imageMenu['image']) && $this->shouldIncludeImage($imageMenu['image'])) {
                    $sourcePath = $this->publicStoragePath('menu/'.$imageMenu['image']);
                    if (is_file($sourcePath)) {
                        $files['files/menu/'.$imageMenu['image']] = $sourcePath;
                    }
                }
            }

            // C3: menu rows live under 'menus' (schema 1) or 'menu_items' (schema 2).
            $menuRows = $restaurantBackup['menus'] ?? $restaurantBackup['menu_items'] ?? [];
            foreach (array_merge($menuRows, $restaurantBackup['image_menus'] ?? []) as $menuRow) {
                if (! empty($menuRow['image']) && $this->shouldIncludeImage($menuRow['image'])) {
                    $sourcePath = $this->publicStoragePath('menu/'.$menuRow['image']);
                    if (is_file($sourcePath)) {
                        $files['files/menu/'.$menuRow['image']] = $sourcePath;
                    }
                }
            }

            foreach ($restaurantBackup['store_options'] ?? [] as $optionRow) {
                if (($optionRow['option_name'] ?? null) === 'qr_image' && ! empty($optionRow['option_value']) && $this->shouldIncludeImage($optionRow['option_value'])) {
                    $sourcePath = $this->publicStoragePath('store/logo/'.$optionRow['option_value']);
                    if (is_file($sourcePath)) {
                        $files['files/restaurant/logo/'.$optionRow['option_value']] = $sourcePath;
                    }
                }
            }

            $manifest['stores'][] = $restaurantBackup;
        }

        return [
            'manifest' => $manifest,
            'files' => $files,
        ];
    }

    protected function buildTargetMigrationPayload(User $user): array
    {
        $backup = $this->buildFullBackupPayload($user);
        $sourceManifest = $backup['manifest'];
        $subscription = $sourceManifest['user']['subscription'] ?? null;
        $files = [];

        $userEntry = [
            'source_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? null,
            'password_hash' => $user->password,
            'subscription' => $subscription,
            'stores' => [],
        ];

        foreach ($sourceManifest['stores'] ?? [] as $restaurantBackup) {
            $userEntry['stores'][] = $this->buildTargetRestaurantExport($restaurantBackup, $subscription, $files);
        }

        return [
            'manifest' => [
                'source_app' => 'juwri',
                'target_app' => 'hungerino',
                'format' => 'hungerino-target-export',
                'version' => 1,
                'exported_at' => now()->toDateTimeString(),
                'users' => [$userEntry],
            ],
            'files' => $files,
        ];
    }

    protected function buildSourceTargetExportPackage(User $user, array $manifest, array $files): array
    {
        $sourceVersion = config('appinfo.version') ?: config('app.version') ?: '1.0';
        $canonicalLocale = 'en';
        $fallbackLocale = 'en';
        $subscription = $manifest['user']['subscription'] ?? null;
        $latestUpgrade = $this->latestUpgradeForUser($user);
        $subscriptionPayment = $this->buildSubscriptionPaymentSnapshot($user, $subscription, $latestUpgrade);
        $planLabel = $subscription['plan_label'] ?? $this->userPlanLabel($user, $this->subscriptionPlans());
        $packageName = $this->mapSourcePackageNameToHungerino($planLabel);

        $json = [
            'manifest.json' => [
                'source_app' => 'juwri',
                'source_version' => $sourceVersion,
                'exported_at' => now()->toDateTimeString(),
                'store_id' => $this->firstRestaurantId($manifest),
                'source_prefix' => 'qrtest_',
                'canonical_locale' => $canonicalLocale,
                'fallback_locale' => $fallbackLocale,
                'pricing_mode' => 'flat',
                'translation_mode' => 'native',
                'target_hint' => 'Hungerino',
                'notes' => 'Flat Hungerino export package generated from the juwri source export.',
            ],
            'users.json' => [],
            'stores.json' => [],
            'branches.json' => [],
            'currencies.json' => [],
            'payment_gateways.json' => [],
            'packages.json' => [],
            'menus.json' => [],
            'categories.json' => [],
            'items.json' => [],
            'item_translations.json' => [],
            'modifier_groups.json' => [],
            'modifier_group_translations.json' => [],
            'modifier_options.json' => [],
            'item_variations.json' => [],
            'subscription_payments.json' => [],
            'orders.json' => [],
        ];

        $json['users.json'][] = [
            'source_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'phone' => $user->phone ?? null,
            'country' => $user->country ?? null,
            'locale' => $user->lang ?? $canonicalLocale,
            'profile_image' => $user->image,
            'restaurant_ownership' => [],
            'branch_assignment' => null,
            'subscription' => $subscription,
        ];

        $restaurantRecords = [];
        $branchRecords = [];
        $currencyRecords = [];
        $gatewayRecords = [];
        $menuRecords = [];
        $categoryRecords = [];
        $itemRecords = [];
        $itemTranslationRecords = [];
        $modifierGroupRecords = [];
        $modifierGroupTranslationRecords = [];
        $modifierOptionRecords = [];
        $itemVariationRecords = [];
        $subscriptionPaymentRecords = [];
        $orderRecords = [];
        $packages = [];
        $assets = [];

        if (! empty($subscriptionPayment)) {
            $subscriptionPaymentRecords[] = $subscriptionPayment;
        }

        foreach ($manifest['stores'] ?? [] as $restaurantBackup) {
            $store = $restaurantBackup['store'] ?? [];
            $postOptions = $this->indexSourceOptions($restaurantBackup['store_options'] ?? []);
            $storeId = (int) ($store['id'] ?? 0);
            $branchId = $storeId > 0 ? $storeId : count($branchRecords) + 1;
            $restaurantName = $store['title'] ?? $store['name'] ?? ('Restaurant #'.$branchId);
            $rootMenuMap = [];
            $variantGroupLookup = [];

            $restaurantRecords[] = [
                'source_id' => $storeId,
                'title' => $restaurantName,
                'slug' => $store['slug'] ?? null,
                'address' => $store['address'] ?? null,
                'phone' => $store['phone'] ?? null,
                'status' => (int) ($store['status'] ?? 1),
                'description' => $store['description'] ?? null,
                'timezone' => $this->restaurantTimezoneFromOptions($postOptions),
                'country' => $user->country ?? null,
                'theme_color' => $store['color'] ?? null,
                'logo' => $store['main_image'] ?? null,
                'banners' => [
                    'main_image' => $store['main_image'] ?? null,
                    'cover_image' => $store['cover_image'] ?? null,
                    'bio_main_image' => $store['bio_main_image'] ?? null,
                    'bio_cover_image' => $store['bio_cover_image'] ?? null,
                ],
                'custom_css' => $store['css_code'] ?? null,
                'custom_js' => $store['js_code'] ?? null,
                'custom_head' => $store['head_code'] ?? null,
                'custom_footer' => $store['footer_code'] ?? null,
                'google_tag' => $store['google_tag'] ?? null,
                'facebook_pixel' => $store['facebook_pixel'] ?? null,
                'social_links' => [
                    'sociallink1' => $store['sociallink1'] ?? null,
                    'sociallink2' => $store['sociallink2'] ?? null,
                    'sociallink3' => $store['sociallink3'] ?? null,
                    'sociallink4' => $store['sociallink4'] ?? null,
                    'sociallink5' => $store['sociallink5'] ?? null,
                    'sociallink6' => $store['sociallink6'] ?? null,
                    'sociallink7' => $store['sociallink7'] ?? null,
                ],
                'wifi_name' => $postOptions['wifi_name'] ?? null,
                'wifi_password' => $postOptions['wifi_password'] ?? null,
                'tax_mode' => $postOptions['tax_mode'] ?? null,
                'tax_inclusive' => $postOptions['tax_inclusive'] ?? null,
                'include_charges_in_tax_base' => $postOptions['include_charges_in_tax_base'] ?? null,
                'customer_language' => $this->restaurantLanguageFromOptions($postOptions, $canonicalLocale),
                'reservation_flags' => [
                    'show_reservations' => (int) ($store['show_reservations'] ?? 0),
                    'default_table_reservation_status' => $store['default_table_reservation_status'] ?? null,
                ],
                'waiter_request_flags' => [
                    'show_call_waiters' => (int) ($store['show_call_waiters'] ?? 0),
                    'is_waiter_request_enabled' => (int) ($store['is_waiter_request_enabled'] ?? 0),
                ],
                'order_type_flags' => [
                    'allow_customer_orders' => (int) ($store['allow_customer_orders'] ?? 0),
                    'allow_customer_pickup_orders' => (int) ($store['allow_customer_pickup_orders'] ?? 0),
                    'allow_customer_delivery_orders' => (int) ($store['allow_customer_delivery_orders'] ?? 0),
                    'allow_dine_in_orders' => (int) ($store['allow_dine_in_orders'] ?? 0),
                ],
                'open_close_settings' => [
                    'status' => (int) ($store['status'] ?? 1),
                    'trial_ends_at' => $store['trial_ends_at'] ?? null,
                    'license_expire_on' => $store['license_expire_on'] ?? null,
                ],
                'seo_fields' => [
                    'description' => $store['description'] ?? null,
                    'slug' => $store['slug'] ?? null,
                ],
                'item_calories_allergies_support_fields' => [
                    'allow_banner' => (int) ($store['allow_banner'] ?? 0),
                ],
            ];

            $currencyCode = strtoupper((string) ($postOptions['currency_code'] ?? config('settings.currency_code') ?? 'USD'));
            $currencyRecords[] = [
                'store_id' => $storeId,
                'currency_code' => $currencyCode,
                'currency_symbol' => $this->currencySymbolForCode($currencyCode),
                'currency_position' => 'left',
                'decimal_places' => 2,
                'thousand_separator' => ',',
                'decimal_separator' => '.',
            ];

            $gatewayRecords[] = [
                'store_id' => $storeId,
                'paypal' => [
                    'enabled' => (int) ($postOptions['restaurant_paypal_install'] ?? 0),
                    'sandbox_mode' => (int) gateway_is_sandbox($postOptions['restaurant_paypal_sandbox_mode'] ?? null),
                    'client_id' => $postOptions['restaurant_paypal_api_client_id'] ?? null,
                    'secret' => $postOptions['restaurant_paypal_api_secret'] ?? null,
                ],
                'stripe' => [
                    'enabled' => (int) ($postOptions['restaurant_stripe_install'] ?? 0),
                    'publishable_key' => $postOptions['restaurant_stripe_publishable_key'] ?? null,
                    'secret' => $postOptions['restaurant_stripe_secret_key'] ?? null,
                ],
                'razorpay' => [
                    'enabled' => (int) ($postOptions['restaurant_razorpay_install'] ?? 0),
                    'key' => $postOptions['restaurant_razorpay_api_key'] ?? null,
                    'secret' => $postOptions['restaurant_razorpay_secret_key'] ?? null,
                ],
                'paystack' => [
                    'enabled' => (int) ($postOptions['restaurant_paystack_install'] ?? 0),
                    'public_key' => $postOptions['restaurant_paystack_public_key'] ?? null,
                    'secret_key' => $postOptions['restaurant_paystack_secret_key'] ?? null,
                ],
                'flutterwave' => [
                    'enabled' => 0,
                    'mode' => 'test',
                ],
                'mollie' => [
                    'enabled' => (int) ($postOptions['restaurant_mollie_install'] ?? 0),
                    'api_key' => $postOptions['restaurant_mollie_api_key'] ?? null,
                ],
                'epay' => [
                    'enabled' => 0,
                    'mode' => 'sandbox',
                ],
                'tap' => [
                    'enabled' => 0,
                    'mode' => 'sandbox',
                ],
                'cash_toggle' => (int) ($postOptions['restaurant_cash_install'] ?? 0),
                'qr_toggle' => (int) ($postOptions['restaurant_qr_pay_install'] ?? 0),
            ];

            $packages[] = [
                'store_id' => $storeId,
                'package_name' => $packageName,
                'original_package_name' => $planLabel,
                'source_package_name' => $planLabel,
                'start_date' => $subscription['start_date'] ?? null,
                'start_date_iso' => $subscription['start_date_iso'] ?? null,
                'expiry_date' => $subscription['expiry_date'] ?? null,
                'expiry_date_iso' => $subscription['expiry_date'] ?? null,
            ];

            $branchRecords[] = [
                'source_id' => $branchId,
                'store_id' => $storeId,
                'name' => $restaurantName.' Branch',
                'address' => $store['address'] ?? null,
                'phone' => $store['phone'] ?? null,
                'work_hours' => $this->restaurantWorkHoursFromOptions($postOptions),
                'primary' => true,
            ];

            $json['users.json'][0]['restaurant_ownership'][] = [
                'store_id' => $storeId,
                'branch_id' => $branchId,
            ];

            foreach ($restaurantBackup['categories'] ?? [] as $categoryRow) {
                $categoryId = (int) ($categoryRow['id'] ?? 0);
                $parentId = (int) ($categoryRow['parent'] ?? 0);
                if ($parentId <= 0) {
                    $rootMenuMap[$categoryId] = $categoryId ?: count($rootMenuMap) + 1;
                }

                $categoryRecords[] = [
                    'source_id' => $categoryId,
                    'branch_id' => $branchId,
                    'parent_id' => $parentId > 0 ? $parentId : null,
                    'category_name' => $this->primaryLabel($categoryRow['title'] ?? $categoryRow['name'] ?? null, $categoryRow['translations'] ?? null) ?: ($categoryRow['title'] ?? $categoryRow['name'] ?? 'Category'),
                    'sort_order' => (int) ($categoryRow['position'] ?? 0),
                    'translations_payload' => $this->buildTranslationPayload($categoryRow['title'] ?? $categoryRow['name'] ?? 'Category', $categoryRow['translations'] ?? null, $canonicalLocale),
                ];
            }

            foreach ($restaurantBackup['menus'] ?? [] as $menuRow) {
                $menuId = (int) ($menuRow['id'] ?? 0);
                $categoryId = (int) ($menuRow['category_id'] ?? 0);
                $menuName = $this->primaryLabel($menuRow['title'] ?? $menuRow['name'] ?? null, $menuRow['translations'] ?? null) ?: ($menuRow['title'] ?? $menuRow['name'] ?? 'Item');

                $menuRecord = [
                    'source_id' => $menuId,
                    'branch_id' => $branchId,
                    'menu_id' => $rootMenuMap[$categoryId] ?? $categoryId ?: null,
                    'category_id' => $categoryId ?: null,
                    'menu_name' => $menuName,
                    'description' => $menuRow['description'] ?? null,
                    'price' => $menuRow['price'] ?? 0,
                    'type' => $menuRow['type'] ?? 'item',
                    'image' => $menuRow['image'] ?? null,
                    'availability' => (int) ($menuRow['status'] ?? $menuRow['available'] ?? 1),
                    'calories' => $menuRow['calories'] ?? null,
                    'allergies' => $menuRow['allergies'] ?? null,
                    'preparation_time' => $menuRow['preparation_time'] ?? $menuRow['prep_time'] ?? null,
                    'translations_payload' => $this->buildTranslationPayload($menuName, $menuRow['translations'] ?? null, $canonicalLocale, $menuRow['description'] ?? null),
                ];

                $menuRecords[] = $menuRecord;
                $itemRecord = $menuRecord;
                $itemRecord['item_name'] = $menuName;
                unset($itemRecord['menu_name']);
                $itemRecords[] = $itemRecord;
                $itemTranslationRecords[] = [
                    'item_id' => $menuId,
                    'locale' => $canonicalLocale,
                    'item_name' => $menuName,
                    'description' => $menuRow['description'] ?? null,
                ];

                if (! empty($menuRecord['image']) && $this->shouldIncludeImage($menuRecord['image'])) {
                    $sourcePath = $this->publicStoragePath('menu/'.$menuRecord['image']);
                    if (is_file($sourcePath)) {
                        $assets['assets/menu/'.$menuRecord['image']] = $sourcePath;
                    }
                }
            }

            foreach ($restaurantBackup['menu_extras'] ?? [] as $extraRow) {
                $groupId = (int) ($extraRow['menu_id'] ?? 0);
                $groupName = 'Extras - '.($extraRow['name'] ?? $extraRow['title'] ?? 'Item');
                $modifierGroupRecords[] = [
                    'source_group_id' => $groupId,
                    'branch_id' => $branchId,
                    'name' => $groupName,
                    'description' => $extraRow['description'] ?? null,
                    'translations_payload' => $this->buildTranslationPayload($groupName, null, $canonicalLocale, $extraRow['description'] ?? null),
                ];
                $modifierOptionRecords[] = [
                    'source_option_id' => (int) ($extraRow['id'] ?? 0),
                    'modifier_group_id' => $groupId,
                    'name' => $extraRow['name'] ?? $extraRow['title'] ?? 'Option',
                    'price' => $extraRow['price'] ?? 0,
                    'availability' => (int) ($extraRow['status'] ?? 1),
                    'sort_order' => (int) ($extraRow['position'] ?? 0),
                    'translations_payload' => $this->buildTranslationPayload($extraRow['name'] ?? 'Option', null, $canonicalLocale),
                ];
                $modifierGroupTranslationRecords[] = [
                    'modifier_group_id' => $groupId,
                    'locale' => $canonicalLocale,
                    'name' => $groupName,
                    'description' => $extraRow['description'] ?? null,
                ];
            }

            foreach ($restaurantBackup['menu_variants'] ?? [] as $variantRow) {
                $groupId = (int) ($variantRow['id'] ?? 0);
                $groupName = $this->primaryLabel($variantRow['title'] ?? $variantRow['name'] ?? null, $variantRow['translations'] ?? null) ?: 'Options';
                $modifierGroupRecords[] = [
                    'source_group_id' => $groupId,
                    'branch_id' => $branchId,
                    'name' => $groupName,
                    'description' => null,
                    'translations_payload' => $this->buildTranslationPayload($groupName, $variantRow['translations'] ?? null, $canonicalLocale),
                ];
                $modifierGroupTranslationRecords[] = [
                    'modifier_group_id' => $groupId,
                    'locale' => $canonicalLocale,
                    'name' => $groupName,
                    'description' => null,
                ];

                $decodedOptions = $this->decodeVariantOptionLabels($variantRow['options'] ?? null);
                $menuId = (int) ($variantRow['menu_id'] ?? 0);
                $variantGroupLookup[$menuId][$groupId] = [
                    'label' => $groupName,
                    'options' => $decodedOptions,
                ];

                $itemVariationRecords[] = [
                    'record_type' => 'variation_group',
                    'source_item_id' => $menuId,
                    'source_group_id' => $groupId,
                    'label' => $groupName,
                    'options' => $decodedOptions,
                    'translations_payload' => $this->buildTranslationPayload($groupName, $variantRow['translations'] ?? null, $canonicalLocale),
                ];
            }

            foreach ($restaurantBackup['menu_variant_options'] ?? [] as $variantOptionRow) {
                $menuId = (int) ($variantOptionRow['menu_id'] ?? 0);
                $groupId = (int) ($variantOptionRow['id'] ?? 0);
                $groupName = $this->primaryLabel($variantOptionRow['title'] ?? $variantOptionRow['name'] ?? null, $variantOptionRow['translations'] ?? null) ?: 'Options';
                $decodedOptions = $this->decodeVariantOptionLabels($variantOptionRow['options'] ?? null);
                $modifierOptionRecords[] = [
                    'source_option_id' => $groupId,
                    'modifier_group_id' => $menuId,
                    'name' => $variantOptionRow['name'] ?? $variantOptionRow['title'] ?? 'Option',
                    'price' => $variantOptionRow['price'] ?? 0,
                    'availability' => (int) ($variantOptionRow['status'] ?? 1),
                    'sort_order' => (int) ($variantOptionRow['position'] ?? 0),
                    'translations_payload' => $this->buildTranslationPayload($variantOptionRow['name'] ?? 'Option', null, $canonicalLocale),
                ];

                $variantGroupLookup[$menuId][$groupId] = [
                    'label' => $groupName,
                    'options' => $decodedOptions,
                ];

                $itemVariationRecords[] = [
                    'record_type' => 'variation_options',
                    'source_item_id' => $menuId,
                    'source_group_id' => $groupId,
                    'label' => $groupName,
                    'options' => $decodedOptions,
                    'translations_payload' => $this->buildTranslationPayload($groupName, $variantOptionRow['translations'] ?? null, $canonicalLocale),
                ];
            }

            foreach ($restaurantBackup['menu_variants'] ?? [] as $variantRow) {
                $menuId = (int) ($variantRow['menu_id'] ?? 0);
                $variationId = (int) ($variantRow['id'] ?? 0);
                $selectionMap = $this->decodeVariantSelectionMap($variantRow['options'] ?? null);
                $selectionLabels = $this->buildVariantSelectionLabels($variantGroupLookup[$menuId] ?? [], $selectionMap);
                $variantLabel = ! empty($selectionLabels)
                    ? implode(' / ', $selectionLabels)
                    : ($this->primaryLabel($variantRow['title'] ?? $variantRow['name'] ?? null, $variantRow['translations'] ?? null)
                        ?: ('Variation #'.$variationId));

                $itemVariationRecords[] = [
                    'record_type' => 'priced_variant',
                    'source_item_id' => $menuId,
                    'source_variation_id' => $variationId,
                    'label' => $variantLabel,
                    'price' => (float) ($variantRow['price'] ?? 0),
                    'availability' => (int) ($variantRow['status'] ?? 1),
                    'sort_order' => (int) ($variantRow['position'] ?? 0),
                    'selection_map' => $selectionMap,
                    'selection_labels' => $selectionLabels,
                    'translations_payload' => $this->buildTranslationPayload($variantLabel, $variantRow['translations'] ?? null, $canonicalLocale),
                ];
            }

            foreach ($restaurantBackup['orders'] ?? [] as $orderBackup) {
                $orderRecords[] = [
                    'restaurant_source_id' => $storeId,
                    'branch_source_id' => $branchId,
                    'metadata' => $orderBackup,
                ];
            }

            foreach (['main_image' => 'store/logo/', 'cover_image' => 'store/cover/', 'bio_main_image' => 'store/logo/', 'bio_cover_image' => 'store/cover/'] as $field => $folder) {
                if (! empty($store[$field]) && $this->shouldIncludeImage($store[$field])) {
                    $sourcePath = $this->publicStoragePath($folder.$store[$field]);
                    if (is_file($sourcePath)) {
                        $assets['assets/'.$folder.$store[$field]] = $sourcePath;
                    }
                }
            }

            foreach ($restaurantBackup['store_options'] ?? [] as $optionRow) {
                if (($optionRow['option_name'] ?? null) === 'qr_image' && ! empty($optionRow['option_value']) && $this->shouldIncludeImage($optionRow['option_value'])) {
                    $sourcePath = $this->publicStoragePath('store/logo/'.$optionRow['option_value']);
                    if (is_file($sourcePath)) {
                        $assets['assets/restaurant/logo/'.$optionRow['option_value']] = $sourcePath;
                    }
                }
            }
        }

        $json['stores.json'] = $restaurantRecords;
        $json['branches.json'] = $branchRecords;
        $json['currencies.json'] = $currencyRecords;
        $json['payment_gateways.json'] = $gatewayRecords;
        $json['menus.json'] = $menuRecords;
        $json['categories.json'] = $categoryRecords;
        $json['items.json'] = $itemRecords;
        $json['item_translations.json'] = $itemTranslationRecords;
        $json['modifier_groups.json'] = $modifierGroupRecords;
        $json['modifier_group_translations.json'] = $modifierGroupTranslationRecords;
        $json['modifier_options.json'] = $modifierOptionRecords;
        $json['item_variations.json'] = $itemVariationRecords;
        $json['subscription_payments.json'] = $subscriptionPaymentRecords;
        $json['orders.json'] = $orderRecords;
        $json['packages.json'] = $packages ?: [[
            'store_id' => $this->firstRestaurantId($manifest),
            'package_name' => $packageName,
            'source_package_name' => $planLabel,
            'start_date' => $subscription['start_date'] ?? null,
            'start_date_iso' => $subscription['start_date_iso'] ?? null,
            'expiry_date' => $subscription['expiry_date'] ?? null,
            'expiry_date_iso' => $subscription['expiry_date'] ?? null,
        ]];

        return [
            'json' => $json,
            'files' => $assets + $files,
        ];
    }

    protected function indexSourceOptions(array $rows): array
    {
        $indexed = ['__rows' => $rows];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = (string) ($row['option_name'] ?? $row['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $value = $row['option_value'] ?? $row['value'] ?? null;
            $indexed[$name] = $value;
        }

        return $indexed;
    }

    protected function decodeVariantOptionLabels($value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded)) {
            return [];
        }

        $labels = [];
        foreach ($decoded as $index => $label) {
            if (is_array($label)) {
                $label = $label['label'] ?? $label['title'] ?? $label['name'] ?? $label['value'] ?? null;
            }

            $label = $this->cleanString($label);
            if ($label === null) {
                continue;
            }

            $labels[(int) $index] = $label;
        }

        return $labels;
    }

    protected function cleanString($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(strip_tags($value));
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);

        return $value !== '' ? $value : null;
    }

    protected function decodeVariantSelectionMap($value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded)) {
            return [];
        }

        $selections = [];
        foreach ($decoded as $groupId => $selectedIndex) {
            if (! is_numeric($selectedIndex)) {
                continue;
            }

            $selections[(int) $groupId] = (int) $selectedIndex;
        }

        return $selections;
    }

    protected function buildVariantSelectionLabels(array $groups, array $selectionMap): array
    {
        $labels = [];
        foreach ($selectionMap as $groupId => $selectedIndex) {
            if (! isset($groups[$groupId])) {
                continue;
            }

            $group = $groups[$groupId];
            $groupLabel = $group['label'] ?? ('Group '.$groupId);
            $optionLabel = $group['options'][$selectedIndex] ?? null;
            if ($optionLabel !== null && $optionLabel !== '') {
                $labels[] = $groupLabel.': '.$optionLabel;

                continue;
            }

            $labels[] = $groupLabel.': '.$selectedIndex;
        }

        return $labels;
    }

    protected function primaryLabel($value, $translations = null): ?string
    {
        $label = $this->translationValueForLocale($translations, get_lang());
        if ($label !== null && $label !== '') {
            return $label;
        }

        $label = $this->translationValueForLocale($translations, 'en');
        if ($label !== null && $label !== '') {
            return $label;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    protected function translationValueForLocale($translations, string $locale): ?string
    {
        if ($translations === null) {
            return null;
        }

        if (is_string($translations)) {
            return trim($translations) !== '' ? $translations : null;
        }

        if (! is_array($translations)) {
            return null;
        }

        if (isset($translations[$locale])) {
            $entry = $translations[$locale];
            if (is_string($entry)) {
                return trim($entry) !== '' ? $entry : null;
            }

            if (is_array($entry)) {
                $candidate = $entry['name'] ?? $entry['title'] ?? $entry['value'] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    return $candidate;
                }
            }
        }

        foreach ($translations as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['locale'] ?? null) !== $locale) {
                continue;
            }

            $candidate = $entry['name'] ?? $entry['title'] ?? $entry['value'] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    protected function buildTranslationPayload($value, $translations, string $locale, ?string $description = null): array
    {
        $normalizedTranslations = [];

        if (is_array($translations)) {
            $isLocaleMap = ! empty($translations) && array_filter(array_keys($translations), 'is_string');
            if ($isLocaleMap) {
                foreach ($translations as $translationLocale => $translationValue) {
                    if (is_array($translationValue)) {
                        $normalizedTranslations[$translationLocale] = [
                            'name' => $translationValue['name'] ?? $translationValue['title'] ?? $translationValue['value'] ?? null,
                            'description' => $translationValue['description'] ?? null,
                        ];

                        continue;
                    }

                    $normalizedTranslations[$translationLocale] = [
                        'name' => $translationValue,
                        'description' => null,
                    ];
                }
            } else {
                foreach ($translations as $translationRow) {
                    if (! is_array($translationRow)) {
                        continue;
                    }

                    $translationLocale = (string) ($translationRow['locale'] ?? '');
                    if ($translationLocale === '') {
                        continue;
                    }

                    $normalizedTranslations[$translationLocale] = [
                        'name' => $translationRow['name'] ?? $translationRow['title'] ?? $translationRow['value'] ?? null,
                        'description' => $translationRow['description'] ?? null,
                    ];
                }
            }
        } elseif (is_string($translations) && trim($translations) !== '') {
            $normalizedTranslations[$locale] = [
                'name' => $translations,
                'description' => $description,
            ];
        }

        $canonicalValue = is_string($value) ? trim($value) : $value;
        if ($canonicalValue === '') {
            $canonicalValue = null;
        }

        if ($canonicalValue !== null) {
            $normalizedTranslations[$locale] = [
                'name' => $canonicalValue,
                'description' => $description,
            ];
        }

        return [
            'canonical_locale' => $locale,
            'fallback_locale' => $locale,
            'canonical_value' => $canonicalValue,
            'canonical_description' => $description,
            'translations' => $normalizedTranslations,
            'raw_translations' => $translations,
        ];
    }

    protected function firstRestaurantId(array $manifest): ?int
    {
        foreach ($manifest['stores'] ?? [] as $restaurantBackup) {
            $store = $restaurantBackup['store'] ?? [];
            $storeId = (int) ($store['id'] ?? $restaurantBackup['id'] ?? 0);
            if ($storeId > 0) {
                return $storeId;
            }
        }

        return null;
    }

    protected function restaurantTimezoneFromOptions(array $options): ?string
    {
        foreach (['timezone', 'time_zone', 'restaurant_timezone', 'post_timezone'] as $key) {
            $value = $options[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return config('app.timezone') ?: null;
    }

    protected function restaurantLanguageFromOptions(array $options, string $fallback): string
    {
        foreach (['language', 'locale', 'customer_language', 'restaurant_language'] as $key) {
            $value = $options[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $fallback;
    }

    protected function restaurantWorkHoursFromOptions(array $options)
    {
        foreach (['work_hours', 'work_time', 'timing', 'opening_hours', 'opening_time'] as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null && $options[$key] !== '') {
                return $options[$key];
            }
        }

        return null;
    }

    protected function currencySymbolForCode(string $currencyCode): string
    {
        // S10: thin wrapper over the one authoritative map in Helper.php.
        return currency_symbol_for($currencyCode);
    }

    protected function mapSourcePackageNameToHungerino(string $sourceName): string
    {
        $mapping = [
            'Free Package' => 'Default',
            'Try For 7 Days' => 'Trial Package التجريبية',
            'Main Package' => 'Standard Package',
            'Pro Packege' => 'Pro Package',
            'Premium Package' => 'Standard Package',
            'Show Product Only' => 'View Only',
        ];

        return $mapping[$sourceName] ?? $sourceName;
    }

    protected function buildTargetRestaurantExport(array $restaurantBackup, ?array $subscription, array &$files): array
    {
        $store = $restaurantBackup['store'] ?? [];
        $storeId = (int) ($store['id'] ?? 0);
        $branch = $this->buildTargetBranchExport($restaurantBackup, $files);

        foreach (['main_image' => 'store/logo/', 'cover_image' => 'store/cover/', 'bio_main_image' => 'store/logo/', 'bio_cover_image' => 'store/cover/'] as $field => $folder) {
            if (! empty($store[$field]) && $this->shouldIncludeImage($store[$field])) {
                $sourcePath = $this->publicStoragePath($folder.$store[$field]);
                if (is_file($sourcePath)) {
                    $files['files/'.$folder.$store[$field]] = $sourcePath;
                }
            }
        }

        return [
            'source_id' => $storeId,
            'restaurant' => [
                'source_id' => $storeId,
                'name' => $store['title'] ?? $store['name'] ?? ('Restaurant #'.$storeId),
                'slug' => $store['slug'] ?? null,
                'address' => $store['address'] ?? null,
                'phone' => $store['phone'] ?? null,
                'description' => $store['description'] ?? null,
                'status' => $store['status'] ?? null,
                'logo' => $store['main_image'] ?? null,
                'cover_image' => $store['cover_image'] ?? null,
            ],
            'subscription' => $subscription,
            'branches' => [$branch],
        ];
    }

    protected function buildTargetBranchExport(array $restaurantBackup, array &$files): array
    {
        $store = $restaurantBackup['store'] ?? [];
        $storeId = (int) ($store['id'] ?? 0);
        $categories = $restaurantBackup['categories'] ?? [];
        $menus = $restaurantBackup['menus'] ?? [];

        $rootMenus = [];
        foreach ($categories as $categoryRow) {
            $parentId = (int) ($categoryRow['parent'] ?? 0);
            if ($parentId > 0) {
                continue;
            }

            $rootMenus[(int) ($categoryRow['id'] ?? 0)] = [
                'source_id' => (int) ($categoryRow['id'] ?? 0),
                'name' => $categoryRow['title'] ?? $categoryRow['name'] ?? 'Menu',
                'sort_order' => (int) ($categoryRow['position'] ?? 0),
            ];
        }

        $branch = [
            'source_id' => $storeId,
            'branch' => [
                'source_id' => $storeId,
                'name' => $store['title'] ?? $store['name'] ?? 'Main Branch',
                'address' => $store['address'] ?? null,
                'phone_number' => $store['phone'] ?? null,
                'work_time' => $store['timing'] ?? null,
                'is_primary' => true,
            ],
            'tables' => array_map(function ($tableRow) {
                return [
                    'source_id' => (int) ($tableRow['id'] ?? 0),
                    'table_number' => $tableRow['table_no'] ?? $tableRow['table_number'] ?? null,
                    'key' => $tableRow['key'] ?? null,
                ];
            }, $restaurantBackup['tables'] ?? []),
            'menus' => array_values($rootMenus),
            'categories' => array_map(function ($categoryRow) {
                return [
                    'source_id' => (int) ($categoryRow['id'] ?? 0),
                    'parent_source_id' => (int) ($categoryRow['parent'] ?? 0) ?: null,
                    'name' => $categoryRow['title'] ?? $categoryRow['name'] ?? 'Category',
                    'sort_order' => (int) ($categoryRow['position'] ?? 0),
                ];
            }, $categories),
            'items' => array_map(function ($menuRow) {
                return [
                    'source_id' => (int) ($menuRow['id'] ?? 0),
                    'category_source_id' => (int) ($menuRow['category_id'] ?? 0) ?: null,
                    'name' => $menuRow['title'] ?? $menuRow['name'] ?? 'Item',
                    'description' => $menuRow['description'] ?? null,
                    'price' => $menuRow['price'] ?? null,
                    'image' => $menuRow['image'] ?? null,
                    'sort_order' => (int) ($menuRow['position'] ?? 0),
                ];
            }, $menus),
            'modifier_groups' => [],
            'modifier_options' => [],
            'item_modifiers' => [],
            'orders' => $restaurantBackup['orders'] ?? [],
            'settings' => $restaurantBackup['store_options'] ?? [],
        ];

        foreach ($restaurantBackup['image_menus'] ?? [] as $imageMenu) {
            if (! empty($imageMenu['image']) && $this->shouldIncludeImage($imageMenu['image'])) {
                $sourcePath = $this->publicStoragePath('menu/'.$imageMenu['image']);
                if (is_file($sourcePath)) {
                    $files['files/menu/'.$imageMenu['image']] = $sourcePath;
                }
            }
        }

        foreach (array_merge($menus, $restaurantBackup['image_menus'] ?? []) as $menuRow) {
            if (! empty($menuRow['image']) && $this->shouldIncludeImage($menuRow['image'])) {
                $sourcePath = $this->publicStoragePath('menu/'.$menuRow['image']);
                if (is_file($sourcePath)) {
                    $files['files/menu/'.$menuRow['image']] = $sourcePath;
                }
            }
        }

        foreach ($restaurantBackup['menu_extras'] ?? [] as $extraRow) {
            $branch['modifier_groups'][] = [
                'source_id' => (int) ($extraRow['menu_id'] ?? 0),
                'kind' => 'extras',
                'name' => 'Extras - '.($extraRow['title'] ?? $extraRow['name'] ?? 'Item'),
                'source_item_id' => (int) ($extraRow['menu_id'] ?? 0),
            ];

            $branch['modifier_options'][] = [
                'source_id' => (int) ($extraRow['id'] ?? 0),
                'group_source_id' => (int) ($extraRow['menu_id'] ?? 0),
                'name' => $extraRow['name'] ?? $extraRow['title'] ?? 'Option',
                'price' => $extraRow['price'] ?? 0,
            ];
        }

        foreach ($restaurantBackup['menu_variants'] ?? [] as $variantRow) {
            $branch['modifier_groups'][] = [
                'source_id' => (int) ($variantRow['id'] ?? 0),
                'kind' => 'variants',
                'name' => $variantRow['title'] ?? $variantRow['name'] ?? 'Options',
                'source_item_id' => (int) ($variantRow['menu_id'] ?? 0),
            ];
        }

        foreach ($restaurantBackup['menu_variant_options'] ?? [] as $variantOptionRow) {
            $branch['modifier_options'][] = [
                'source_id' => (int) ($variantOptionRow['id'] ?? 0),
                'group_source_id' => (int) ($variantOptionRow['menu_id'] ?? 0),
                'name' => $variantOptionRow['name'] ?? $variantOptionRow['title'] ?? 'Option',
                'price' => $variantOptionRow['price'] ?? 0,
            ];
        }

        foreach ($menus as $menuRow) {
            if (! empty($menuRow['category_id'])) {
                $branch['item_modifiers'][] = [
                    'menu_source_id' => (int) ($menuRow['id'] ?? 0),
                    'category_source_id' => (int) ($menuRow['category_id'] ?? 0),
                ];
            }
        }

        foreach ($restaurantBackup['store_options'] ?? [] as $optionRow) {
            if (($optionRow['option_name'] ?? null) === 'qr_image' && ! empty($optionRow['option_value']) && $this->shouldIncludeImage($optionRow['option_value'])) {
                $sourcePath = $this->publicStoragePath('store/logo/'.$optionRow['option_value']);
                if (is_file($sourcePath)) {
                    $files['files/restaurant/logo/'.$optionRow['option_value']] = $sourcePath;
                }
            }
        }

        return $branch;
    }

    protected function restoreFullBackup(User $user, array $manifest, string $extractDir, array &$createdFiles, bool $allowPartial = false): void
    {
        $userData = $manifest['user'];
        $complete = $this->manifestIsRegistryComplete($manifest);

        /* C3: refuse to run the destructive clear against a manifest that cannot be
           fully restored, unless the operator has explicitly accepted the loss. */
        if (! $complete && ! $allowPartial) {
            throw new \RuntimeException(
                'This archive predates the full table registry. Restoring it would delete every '
                .'store and re-create only part of it, permanently orphaning the rest. Re-export '
                .'the user with the registry engine on, or tick "I accept a partial, lossy restore".'
            );
        }

        $this->clearExistingUserData($user, $complete);

        $updatedUserData = $this->filterRowForInsert(LegacyMoneyColumns::upgradeRow('users', $userData, platform_currency()), 'users', ['id', 'remember_token']); // P1-05: balance
        unset(
            $updatedUserData['subscription'],
            $updatedUserData['options'],
            $updatedUserData['upgrades'],
            $updatedUserData['transactions'],
            $updatedUserData['blog_comments']
        );
        $updatedUserData['group_id'] = $userData['group_id'] ?? $user->group_id;
        $updatedUserData['updated_at'] = now()->toDateTimeString();
        $user->forceFill($updatedUserData);
        $user->save();
        /* R6 — dual-write resync (bulk import may rewrite group_id). */
        app(Subscriptions::class)->resyncFromLegacy($user->fresh(), 'admin', auth()->id());

        foreach (($userData['options'] ?? []) as $optionRow) {
            if (isset($optionRow['option_name'])) {
                UserSettings::set((int) $user->id, (string) $optionRow['option_name'], $optionRow['option_value'] ?? null);
            }
        }

        foreach (($userData['upgrades'] ?? []) as $upgradeRow) {
            $upgradeRow['user_id'] = $user->id;
            DB::table('upgrades')->insert(TenantFill::row(DB::connection(), 'upgrades', $this->filterRowForInsert($upgradeRow, 'upgrades', ['upgrade_id'])));
        }

        foreach (($userData['transactions'] ?? []) as $transactionRow) {
            $transactionRow['user_id'] = $user->id;
            // P1-05: an older archive carries amount/base_amount (major units) — keep them as *_minor.
            $transactionRow = LegacyMoneyColumns::upgradeRow('transaction', $transactionRow, platform_currency());
            DB::table('transaction')->insert(TenantFill::row(DB::connection(), 'transaction', $this->filterRowForInsert($transactionRow, 'transaction', ['id'])));
        }

        foreach (($userData['blog_comments'] ?? []) as $commentRow) {
            $commentRow['user_id'] = $user->id;
            if (! empty($commentRow['blog_id']) && ! DB::table('blog_posts')->where('id', $commentRow['blog_id'])->exists()) {
                continue;
            }
            DB::table('blog_comments')->insert($this->filterRowForInsert($commentRow, 'blog_comments', ['id']));
        }

        $blogCategoryMap = [];
        foreach ($manifest['blog_categories'] ?? [] as $categoryRow) {
            $oldCategoryId = (int) ($categoryRow['id'] ?? 0);
            if ($oldCategoryId <= 0) {
                continue;
            }

            $existingCategory = null;
            if (! empty($categoryRow['slug'])) {
                $existingCategory = BlogCategory::where('slug', $categoryRow['slug'])->first();
            }
            if (! $existingCategory && ! empty($categoryRow['title'])) {
                $existingCategory = BlogCategory::where('title', $categoryRow['title'])->first();
            }

            if ($existingCategory) {
                $blogCategoryMap[$oldCategoryId] = $existingCategory->id;

                continue;
            }

            $insertData = $this->filterRowForInsert($categoryRow, 'blog_categories', ['id']);
            $insertData['position'] = $insertData['position'] ?? 0;
            $insertData['active'] = $insertData['active'] ?? 1;
            $blogCategoryMap[$oldCategoryId] = DB::table('blog_categories')->insertGetId($insertData);
        }

        foreach ($manifest['blogs'] ?? [] as $blogBackup) {
            $blogRow = $this->filterRowForInsert($blogBackup['post'] ?? [], 'blog_posts', ['id']);
            $blogRow['author'] = $user->id;
            $blogRow['created_at'] = $blogRow['created_at'] ?? now()->toDateTimeString();
            $blogRow['updated_at'] = $blogRow['updated_at'] ?? now()->toDateTimeString();
            $newBlogId = DB::table('blog_posts')->insertGetId($blogRow);

            foreach ($blogBackup['category_ids'] ?? [] as $oldCategoryId) {
                $oldCategoryId = (int) $oldCategoryId;
                if ($oldCategoryId <= 0 || ! isset($blogCategoryMap[$oldCategoryId])) {
                    continue;
                }

                DB::table('blog_category_post')->insert([
                    'blog_id' => $newBlogId,
                    'category_id' => $blogCategoryMap[$oldCategoryId],
                ]);
            }

            $image = $blogBackup['post']['image'] ?? null;
            if ($this->shouldIncludeImage($image)) {
                $sourcePath = $extractDir.DIRECTORY_SEPARATOR.'files/blog/'.$image;
                $targetPath = $this->publicStoragePath('blog/'.$image);
                $this->copyBackupFile($sourcePath, $targetPath, $createdFiles);
            }
        }

        // Edition limits: the restored stores (with their branches) must fit the business's store/branch limits; a
        // refusal aborts the whole restore (the caller's transaction rolls back, so nothing is lost).
        app(StoreLimitGuard::class)->assertStoreSet(AccountProvisioner::forUser($user)->id, array_values(array_map(fn ($backup) => [
            'type' => is_array($backup) ? (($backup['store']['store_type'] ?? null) ?: null) : null,
            'branches' => is_array($backup) ? count((array) ($backup['branches'] ?? [])) : 1,
        ], (array) ($manifest['stores'] ?? []))));

        foreach ($manifest['stores'] ?? [] as $restaurantBackup) {
            if ($complete) {
                // C3: registry engine — id-preserving full restore of every store table.
                $written = app(AdminStoreArchive::class)->restore($user, $restaurantBackup);
                $this->restoreRestaurantFiles($restaurantBackup, $extractDir, $createdFiles);
                $store = Store::find($restaurantBackup['store']['id'] ?? null);
                if ($store) {
                    $this->auditPlatformRestore($store, $user, (int) ($manifest['schema'] ?? 1), count($written), false);
                }
            } else {
                // Legacy partial restore (new id, 12 tables) — the operator accepted the loss.
                $this->restoreRestaurantBackup($user, $restaurantBackup, $extractDir, $createdFiles);
            }
        }
    }

    /**
     * C3: record a superadmin platform restore on the owner's activity log and,
     * when the store belongs to a reseller's customer, on the reseller audit log.
     * Both writers swallow their own errors, so this can never break a restore.
     */
    protected function auditPlatformRestore(Store $store, User $user, int $schema, int $tables, bool $partial): void
    {
        log_activity('store.platform_restore', $store, [
            'actor' => 'superadmin',
            'schema' => $schema,
            'tables' => $tables,
            'partial' => $partial,
        ], (int) $store->id);

        $resellerId = (int) ($user->reseller_id ?? 0);
        if ($resellerId > 0) {
            app(ResellerContext::class)->audit($resellerId, 'customer.platform_restore', [
                'user_id' => $user->id,
                'store_id' => $store->id,
                'partial' => $partial,
            ], (int) (auth()->id() ?? 0));
        }
    }

    /**
     * C3: the registry archive restores DB rows; the store's image FILES that the
     * Post delete cascade removed (logo/cover/bio + image_menus) are copied back
     * from the ZIP, using the same folder map the export bundled them under
     * (buildFullBackupPayload). media_assets files are NOT deleted by that cascade,
     * so their rows (restored with preserved paths) still point at live files.
     *
     * @param  array<string, mixed>  $restaurantBackup
     * @param  array<int|string, mixed>  $createdFiles
     */
    protected function restoreRestaurantFiles(array $restaurantBackup, string $extractDir, array &$createdFiles): void
    {
        $store = (array) ($restaurantBackup['store'] ?? []);
        foreach (['main_image' => 'store/logo/', 'cover_image' => 'store/cover/', 'bio_main_image' => 'store/logo/', 'bio_cover_image' => 'store/cover/'] as $field => $folder) {
            $image = $store[$field] ?? null;
            if ($this->shouldIncludeImage($image)) {
                $this->copyBackupFile(
                    $extractDir.DIRECTORY_SEPARATOR.'files/'.$folder.$image,
                    $this->publicStoragePath($folder.$image),
                    $createdFiles
                );
            }
        }

        foreach ((array) ($restaurantBackup['image_menus'] ?? []) as $imageMenuRow) {
            $image = is_array($imageMenuRow) ? ($imageMenuRow['image'] ?? null) : null;
            if ($this->shouldIncludeImage($image)) {
                $this->copyBackupFile(
                    $extractDir.DIRECTORY_SEPARATOR.'files/menu/'.$image,
                    $this->publicStoragePath('menu/'.$image),
                    $createdFiles
                );
            }
        }
    }

    protected function restoreRestaurantBackup(User $user, array $restaurantBackup, string $extractDir, array &$createdFiles): void
    {
        $restaurantRow = $this->filterRowForInsert(LegacyMoneyColumns::upgradeRow('stores', (array) ($restaurantBackup['store'] ?? []),
            AdminStoreArchive::archivedCurrency($restaurantBackup)), 'stores', ['id']); // P1-05: mk_min_order
        $restaurantRow['user_id'] = $user->id;
        $restaurantRow['account_id'] = AccountProvisioner::forUser($user)->id;
        $restaurantRow['created_at'] = $restaurantRow['created_at'] ?? now()->toDateTimeString();
        $restaurantRow['updated_at'] = $restaurantRow['updated_at'] ?? now()->toDateTimeString();
        $storeId = DB::table('stores')->insertGetId($restaurantRow);

        $categoryMap = [];
        $menuExtraMap = [];
        $variantMap = [];
        $categories = $restaurantBackup['categories'] ?? [];
        $remainingCategories = $categories;

        while (! empty($remainingCategories)) {
            $progress = false;
            foreach ($remainingCategories as $index => $categoryRow) {
                $oldCategoryId = (int) ($categoryRow['id'] ?? 0);
                $oldParentId = (int) ($categoryRow['parent'] ?? 0);
                if ($oldParentId > 0 && ! isset($categoryMap[$oldParentId])) {
                    continue;
                }

                $insertData = $this->filterRowForInsert($categoryRow, 'categories', ['id']);
                $insertData['store_id'] = $storeId;
                $insertData['parent'] = $oldParentId > 0 ? $categoryMap[$oldParentId] : null;
                $categoryMap[$oldCategoryId] = DB::table('categories')->insertGetId(TenantFill::row(DB::connection(), 'categories', $insertData));
                unset($remainingCategories[$index]);
                $progress = true;
            }

            if (! $progress) {
                throw new \RuntimeException('Unable to restore menu categories because of an unresolved parent reference.');
            }
        }

        $menuMap = [];
        foreach ($restaurantBackup['menus'] ?? [] as $menuRow) {
            $oldMenuId = (int) ($menuRow['id'] ?? 0);
            $oldCategoryId = (int) ($menuRow['category_id'] ?? 0);

            // P1-05: an older archive carries decimal `price`/`cost` — convert to *_minor in the store currency.
            $menuRow = LegacyMoneyColumns::upgradeRow('menu', $menuRow, store_currency($storeId));
            $insertData = $this->filterRowForInsert($menuRow, 'items', ['id']);
            $insertData['store_id'] = $storeId;
            $insertData['category_id'] = $oldCategoryId > 0 && isset($categoryMap[$oldCategoryId]) ? $categoryMap[$oldCategoryId] : null;
            $menuMap[$oldMenuId] = DB::table('items')->insertGetId(TenantFill::row(DB::connection(), 'items', $insertData));
        }

        foreach ($restaurantBackup['menu_extras'] ?? [] as $extraRow) {
            $oldMenuId = (int) ($extraRow['menu_id'] ?? 0);
            if ($oldMenuId <= 0 || ! isset($menuMap[$oldMenuId])) {
                continue;
            }

            $oldExtraId = (int) ($extraRow['id'] ?? 0);
            $extraRow = LegacyMoneyColumns::upgradeRow('menu_extras', $extraRow, store_currency($storeId));
            // P1-06: an add-on is a `modifiers` row in its item's add-on group (found or created by the model).
            $insertData = array_intersect_key(
                $this->filterRowForInsert($extraRow, 'modifiers', ['id', 'public_id', 'modifier_group_id', 'kind']),
                array_flip(Schema::getColumnListing('modifiers'))
            );
            $insertData['menu_id'] = $menuMap[$oldMenuId];
            $insertData['store_id'] = $storeId; // P1-04: never the archived (old) store id
            $extra = new MenuExtra;
            $extra->setRawAttributes($insertData);
            $extra->save();
            $menuExtraMap[$oldExtraId] = (int) $extra->id;
        }

        // P1-06: option groups before the priced variants, whose `options` cell names groups by id ({group id: option index}).
        $variantGroupMap = [];
        foreach ($restaurantBackup['menu_variant_options'] ?? [] as $optionRow) {
            $oldMenuId = (int) ($optionRow['menu_id'] ?? 0);
            if ($oldMenuId <= 0 || ! isset($menuMap[$oldMenuId])) {
                continue;
            }

            $insertData = array_intersect_key(
                $this->filterRowForInsert($optionRow, 'modifier_groups', ['id', 'public_id', 'kind']),
                array_flip(Schema::getColumnListing('modifier_groups'))
            );
            $insertData['menu_id'] = $menuMap[$oldMenuId];
            $insertData['store_id'] = $storeId; // P1-04: never the archived (old) store id
            $group = new MenuVariantOption;
            $group->setRawAttributes($insertData);
            foreach (['options', 'option_prices', 'option_images'] as $list) {
                if (array_key_exists($list, $optionRow)) {
                    $group->{$list} = $optionRow[$list];
                }
            }
            $group->save();
            $variantGroupMap[(int) ($optionRow['id'] ?? 0)] = (int) $group->id;
        }

        foreach ($restaurantBackup['menu_variants'] ?? [] as $variantRow) {
            $oldMenuId = (int) ($variantRow['menu_id'] ?? 0);
            if ($oldMenuId <= 0 || ! isset($menuMap[$oldMenuId])) {
                continue;
            }

            $oldVariantId = (int) ($variantRow['id'] ?? 0);
            $variantRow = LegacyMoneyColumns::upgradeRow('menu_variants', $variantRow, store_currency($storeId));
            $insertData = $this->filterRowForInsert($variantRow, 'menu_variants', ['id']);
            $insertData['menu_id'] = $menuMap[$oldMenuId];
            $insertData['options'] = $this->remapVariantGroupKeys($insertData['options'] ?? null, $variantGroupMap);
            // P3-02: a restored variant is a new row — a fresh public id (an older archive has none; a copied one could
            // collide with the archived store's row).
            $insertData['public_id'] = (string) Str::uuid7();
            $variantMap[$oldVariantId] = DB::table('menu_variants')->insertGetId(TenantFill::row(DB::connection(), 'menu_variants', $insertData));
        }

        foreach ($restaurantBackup['store_options'] ?? [] as $optionRow) {
            // P1-07: gateway settings go to store_payment_gateways; a credential is never restored.
            if (StorePaymentGateways::handles($optionRow['option_name'] ?? null)) {
                if (! StorePaymentGateways::isCredential((string) $optionRow['option_name'])) {
                    StorePaymentGateways::put((int) $storeId, (string) $optionRow['option_name'], $optionRow['option_value'] ?? null);
                }

                continue;
            }
            if (isset($optionRow['option_name'])) {
                StoreSettings::set((int) $storeId, (string) $optionRow['option_name'], $optionRow['option_value'] ?? null);
            }
        }

        foreach ($restaurantBackup['tables'] ?? [] as $tableRow) {
            $insertData = $this->filterRowForInsert($tableRow, 'store_tables', ['id']);
            $insertData['store_id'] = $storeId;
            $insertData['key'] = $this->generateUniqueRestaurantTableKey();
            DB::table('store_tables')->insert(TenantFill::row(DB::connection(), 'store_tables', $insertData));
        }

        foreach ($restaurantBackup['image_menus'] ?? [] as $imageMenuRow) {
            $oldImage = $imageMenuRow['image'] ?? null;
            $insertData = $this->filterRowForInsert($imageMenuRow, 'image_menus', ['id']);
            $insertData['store_id'] = $storeId;
            DB::table('image_menus')->insert(TenantFill::row(DB::connection(), 'image_menus', $insertData));

            if ($this->shouldIncludeImage($oldImage)) {
                $sourcePath = $extractDir.DIRECTORY_SEPARATOR.'files/menu/'.$oldImage;
                $targetPath = $this->publicStoragePath('menu/'.$oldImage);
                $this->copyBackupFile($sourcePath, $targetPath, $createdFiles);
            }
        }

        foreach ($restaurantBackup['orders'] ?? [] as $orderBackup) {
            // P1-05: an older archive carries the decimal money columns — convert them in the order's currency.
            $orderSource = LegacyMoneyColumns::upgradeRow('orders', (array) ($orderBackup['order'] ?? []), store_currency($storeId));
            $orderCurrency = (string) ($orderSource['currency_code'] ?? '') ?: store_currency($storeId);
            $orderRow = $this->filterRowForInsert($orderSource, 'orders', ['id']);
            unset($orderRow['branch']);
            $orderRow['store_id'] = $storeId;
            $orderRow['created_at'] = $orderRow['created_at'] ?? now()->toDateTimeString();
            $newOrderId = DB::table('orders')->insertGetId(TenantFill::row(DB::connection(), 'orders', $orderRow));

            foreach ($orderBackup['items'] ?? [] as $itemBackup) {
                $itemRow = $itemBackup['item'] ?? [];
                $oldMenuId = (int) ($itemRow['item_id'] ?? 0);
                $oldVariantId = (int) ($itemRow['variation'] ?? 0);

                $insertItem = $this->filterRowForInsert(LegacyMoneyColumns::upgradeRow('order_items', $itemRow, $orderCurrency), 'order_items', ['id']);
                $insertItem['order_id'] = $newOrderId;
                $insertItem['item_id'] = $oldMenuId > 0 && isset($menuMap[$oldMenuId]) ? $menuMap[$oldMenuId] : null;
                $insertItem['variation'] = $oldVariantId > 0 && isset($variantMap[$oldVariantId]) ? $variantMap[$oldVariantId] : null;
                $newOrderItemId = DB::table('order_items')->insertGetId(TenantFill::row(DB::connection(), 'order_items', $insertItem));

                foreach ($itemBackup['extras'] ?? [] as $itemExtraRow) {
                    $oldExtraId = (int) ($itemExtraRow['extra_id'] ?? 0);
                    if ($oldExtraId <= 0 || ! isset($menuExtraMap[$oldExtraId])) {
                        continue;
                    }

                    $insertExtra = $this->filterRowForInsert(LegacyMoneyColumns::upgradeRow('order_item_extras', $itemExtraRow, $orderCurrency), 'order_item_extras', ['id']);
                    $insertExtra['order_item_id'] = $newOrderItemId;
                    $insertExtra['extra_id'] = $menuExtraMap[$oldExtraId];
                    DB::table('order_item_extras')->insert(TenantFill::row(DB::connection(), 'order_item_extras', $insertExtra));
                }
            }
        }

        foreach ($restaurantBackup['store_options'] ?? [] as $optionRow) {
            if (($optionRow['option_name'] ?? null) === 'qr_image' && ! empty($optionRow['option_value']) && $this->shouldIncludeImage($optionRow['option_value'])) {
                $sourcePath = $extractDir.DIRECTORY_SEPARATOR.'files/restaurant/logo/'.$optionRow['option_value'];
                $targetPath = $this->publicStoragePath('store/logo/'.$optionRow['option_value']);
                $this->copyBackupFile($sourcePath, $targetPath, $createdFiles);
            }
        }

        foreach (['main_image' => 'store/logo/', 'cover_image' => 'store/cover/', 'bio_main_image' => 'store/logo/', 'bio_cover_image' => 'store/cover/'] as $field => $folder) {
            $image = $restaurantBackup['store'][$field] ?? null;
            if ($this->shouldIncludeImage($image)) {
                $sourcePath = $extractDir.DIRECTORY_SEPARATOR.'files/'.$folder.$image;
                $targetPath = $this->publicStoragePath($folder.$image);
                $this->copyBackupFile($sourcePath, $targetPath, $createdFiles);
            }
        }
    }

    protected function clearExistingUserData(User $user, bool $registryComplete = false): void
    {
        Upgrade::where('user_id', $user->id)->delete();
        DB::table('transaction')->where('user_id', $user->id)->delete();
        DB::table('blog_comments')->where('user_id', $user->id)->delete();
        UserSettings::forgetAll((int) $user->id);

        $blogs = Blog::where('author', $user->id)->get();
        foreach ($blogs as $blog) {
            if (! empty($blog->image) && $blog->image !== 'default.png') {
                remove_file('storage/blog/'.$blog->image);
            }
            DB::table('blog_category_post')->where('blog_id', $blog->id)->delete();
            $blog->delete();
        }

        $stores = Store::where('user_id', $user->id)->get();
        foreach ($stores as $store) {
            /* C3: the legacy delete cascade only removes menu_categories + image_menus,
               orphaning the other ~62 store-scoped tables with a store_id/store_id
               still pointing at a soon-to-be-dead posts.id. With the registry engine we
               purge every one of them FIRST, so nothing is ever stranded. Without it,
               the operator has explicitly accepted a partial, lossy restore — record
               that so the loss is visible, never silent. */
            if ($registryComplete) {
                app(StorePurgeService::class)->purge($store);
            } else {
                AlertDispatcher::send(
                    'backup-restore',
                    'Partial (lossy) admin restore accepted for user '.$user->id
                    .' — store '.$store->id.' purged by the legacy cascade only; '
                    .'store-scoped rows outside menu_categories/image_menus are now orphaned.'
                );
            }
            $store->delete();
        }
    }

    /**
     * C3: true when this manifest was produced by an export that covered the full
     * table registry. A manifest that predates the registry can NEVER be restored
     * without loss, so the destructive clear must not run against it unsupervised.
     * Content-based on purpose — a schema-2 archive written by an older, shorter
     * registry must also fail; the schema check is only a fast path.
     *
     * @param  array<string, mixed>  $manifest
     */
    protected function manifestIsRegistryComplete(array $manifest): bool
    {
        if ((int) ($manifest['schema'] ?? 1) < 2) {
            return false;
        }
        $need = array_keys(StoreBackupService::tableRegistry());
        foreach ($manifest['stores'] ?? [] as $r) {
            if (! is_array($r) || array_diff($need, array_keys($r)) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * C3: per existing store, what the restore will DELETE (registry purge, counted
     * only — writes nothing) vs what the archive will RESTORE. Ids are preserved,
     * so an archived store matches the live store on id.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<int, array{name:string, delete:array<string,int>, restore:array<string,int>}>
     */
    protected function buildRestorePlan(User $user, array $manifest): array
    {
        $byId = [];
        foreach ($manifest['stores'] ?? [] as $r) {
            $byId[(int) ($r['store']['id'] ?? 0)] = $r;
        }

        $plan = [];
        foreach (Store::where('user_id', $user->id)->get() as $store) {
            $archive = $byId[$store->id] ?? null;
            $plan[$store->id] = [
                'name' => (string) $store->title,
                'delete' => app(StorePurgeService::class)->purge($store, true),
                'restore' => is_array($archive) ? $this->restorePreviewCounts($archive) : [],
            ];
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $restaurantBackup
     * @return array<string, int>
     */
    protected function restorePreviewCounts(array $restaurantBackup): array
    {
        $out = [];
        $keys = array_merge(
            array_keys(StoreBackupService::tableRegistry()),
            ['store_options', 'theme_settings', 'orders', 'order_items', 'order_item_extras']
        );
        foreach ($keys as $key) {
            $out[$key] = count((array) ($restaurantBackup[$key] ?? []));
        }

        return $out;
    }

    /**
     * C3: the confirm leg of the dry-run. Re-open the persisted archive, re-check
     * completeness (never trust the preview), run the same restore transaction,
     * then delete the persisted ZIP.
     */
    public function fullImportConfirm(Request $request, User $user, string $token): RedirectResponse
    {
        $token = preg_replace('/[^a-z0-9]/', '', strtolower($token));
        $zipPath = storage_path('app/admin-imports/'.$user->id.'/'.$token.'.zip');
        if ($token === '' || ! is_file($zipPath)) {
            app_alert_error(___('The import preview has expired — please upload the archive again.'));

            return back();
        }

        $extractDir = $this->backupTempDirectory().DIRECTORY_SEPARATOR.'extract-'.Str::random(24);
        if (! is_dir($extractDir) && ! mkdir($extractDir, 0775, true) && ! is_dir($extractDir)) {
            app_alert_error('Unable to prepare backup extraction directory.');

            return back();
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->removeDirectory($extractDir);
            app_alert_error('Unable to open the stored backup file.');

            return back();
        }
        try {
            BackupZipValidator::assertSafe($zip);
        } catch (\RuntimeException $e) {
            $zip->close();
            $this->removeDirectory($extractDir);
            app_alert_error($e->getMessage());

            return back();
        }

        if (! $zip->extractTo($extractDir)) {
            $zip->close();
            $this->removeDirectory($extractDir);
            app_alert_error(___('Unable to extract the uploaded backup file.'));

            return back();
        }

        $zip->close();

        $manifest = json_decode((string) @file_get_contents($extractDir.DIRECTORY_SEPARATOR.'manifest.json'), true);
        if (! is_array($manifest) || empty($manifest['user']) || ! is_array($manifest['user'])) {
            $this->removeDirectory($extractDir);
            app_alert_error('Backup archive is invalid.');

            return back();
        }

        $createdFiles = [];
        try {
            DB::transaction(function () use ($user, $manifest, $extractDir, &$createdFiles, $request) {
                $this->restoreFullBackup($user, $manifest, $extractDir, $createdFiles, $request->boolean('allow_partial_restore'));
            });
        } catch (\Throwable $e) {
            foreach ($createdFiles as $createdFile) {
                remove_file($createdFile);
            }
            $this->removeDirectory($extractDir);
            report($e);
            app_alert_error(___('Import failed. Check the file and try again.'));

            return back();
        }

        $this->removeDirectory($extractDir);
        @unlink($zipPath);
        app_alert_success(___('Imported Successfully'));

        return redirect(route('admin.users.index'));
    }

    protected function exportUserRecord(User $user): array
    {
        $record = (array) DB::table('users')->where('id', $user->id)->first();
        unset($record['remember_token']);

        return $record;
    }

    protected function exportSubscriptionSnapshot(User $user, $plans): array
    {
        $latestUpgrade = $this->latestUpgradeForUser($user);

        $expiresAt = null;
        $expiresOn = null;
        $startedAt = null;
        $startedOn = null;
        if ($latestUpgrade && isset($latestUpgrade->upgrade_expires) && is_numeric($latestUpgrade->upgrade_expires)) {
            $expiresAt = Carbon::createFromTimestamp((int) $latestUpgrade->upgrade_expires)->toDateTimeString();
            $expiresOn = Carbon::createFromTimestamp((int) $latestUpgrade->upgrade_expires)->toDateString();
        }
        if ($latestUpgrade && isset($latestUpgrade->upgrade_lasttime) && is_numeric($latestUpgrade->upgrade_lasttime)) {
            $startedAt = Carbon::createFromTimestamp((int) $latestUpgrade->upgrade_lasttime)->toDateTimeString();
            $startedOn = Carbon::createFromTimestamp((int) $latestUpgrade->upgrade_lasttime)->toDateString();
        }

        $activeUpgrade = $latestUpgrade ? (array) $latestUpgrade : null;
        if ($activeUpgrade !== null) {
            $activeUpgrade['upgrade_started_at'] = $startedAt;
            $activeUpgrade['upgrade_started_date'] = $startedOn;
            $activeUpgrade['upgrade_expires_at'] = $expiresAt;
            $activeUpgrade['upgrade_expires_date'] = $expiresOn;
        }

        return [
            'group_id' => $user->group_id,
            'plan_label' => $this->userPlanLabel($user, $plans),
            'plan_id' => is_numeric($user->group_id) ? (int) $user->group_id : $user->group_id,
            'active_upgrade' => $activeUpgrade,
            'start_date' => $startedOn,
            'start_date_iso' => $startedAt,
            'expiry_date' => $expiresOn,
            'expiry_date_iso' => $expiresAt,
        ];
    }

    protected function latestUpgradeForUser(User $user)
    {
        return DB::table('upgrades')
            ->where('user_id', $user->id)
            ->orderByDesc('upgrade_lasttime')
            ->orderByDesc('upgrade_id')
            ->first();
    }

    protected function buildSubscriptionPaymentSnapshot(User $user, ?array $subscription, $latestUpgrade): ?array
    {
        $transactions = DB::table('transaction')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $candidate = null;
        foreach ($transactions as $transaction) {
            $status = strtolower((string) ($transaction->status ?? ''));
            if ($status !== '' && $status !== Transaction::STATUS_SUCCESS) {
                continue;
            }

            if ($this->transactionMatchesUpgrade($transaction, $latestUpgrade, $subscription)) {
                $candidate = $transaction;
                break;
            }

            if ($candidate === null) {
                $candidate = $transaction;
            }
        }

        if (! $candidate && ! $latestUpgrade && empty($subscription)) {
            return null;
        }

        $amount = null;
        if ($candidate) {
            // P1-05: raw row — amounts are *_minor in the row's currency_code (platform currency when unset).
            $currency = (string) (($candidate->currency_code ?? null) ?: platform_currency());
            if ($candidate->amount_minor !== null && $candidate->amount_minor !== '') {
                $amount = Money::majorFromMinor((int) $candidate->amount_minor, $currency);
            } elseif ($candidate->base_amount_minor !== null && $candidate->base_amount_minor !== '') {
                $amount = Money::majorFromMinor((int) $candidate->base_amount_minor, $currency);
            }
        }

        $billingInterval = $this->resolveSubscriptionBillingInterval($latestUpgrade, $candidate);
        $gateway = $candidate ? ($candidate->transaction_gatway ?? $candidate->transaction_method ?? null) : null;
        if ($gateway === null && $latestUpgrade && ! empty($latestUpgrade->pay_mode)) {
            $gateway = $latestUpgrade->pay_mode;
        }

        $transactionId = $candidate ? ($candidate->payment_id ?? $candidate->id ?? null) : null;
        if ($transactionId === null && $latestUpgrade && ! empty($latestUpgrade->unique_id)) {
            $transactionId = $latestUpgrade->unique_id;
        }

        $currencyCode = $candidate ? ($candidate->currency_code ?? platform_currency()) : platform_currency();

        return [
            'source_user_id' => $user->id,
            'source_upgrade_id' => $latestUpgrade->upgrade_id ?? null,
            'source_transaction_id' => $candidate ? ($candidate->id ?? null) : null,
            'plan_label' => $subscription['plan_label'] ?? $this->userPlanLabel($user, $this->subscriptionPlans()),
            'subscription_amount' => $amount,
            'gateway' => $gateway,
            'transaction_id' => $transactionId,
            'transaction_gateway' => $candidate ? ($candidate->transaction_gatway ?? null) : null,
            'transaction_method' => $candidate ? ($candidate->transaction_method ?? null) : null,
            'billing_interval' => $billingInterval,
            'currency_code' => $currencyCode,
            'status' => $candidate ? ($candidate->status ?? null) : ($latestUpgrade ? ($latestUpgrade->status ?? null) : null),
            'paid_at' => $candidate ? ($candidate->created_at ?? null) : null,
            'upgrade_started_at' => $latestUpgrade && is_numeric($latestUpgrade->upgrade_lasttime)
                ? Carbon::createFromTimestamp((int) $latestUpgrade->upgrade_lasttime)->toDateTimeString()
                : null,
            'upgrade_expires_at' => $latestUpgrade && is_numeric($latestUpgrade->upgrade_expires)
                ? Carbon::createFromTimestamp((int) $latestUpgrade->upgrade_expires)->toDateTimeString()
                : null,
            'raw_transaction' => $candidate ? (array) $candidate : null,
        ];
    }

    protected function transactionMatchesUpgrade($transaction, $latestUpgrade, ?array $subscription): bool
    {
        if (! $transaction || ! $latestUpgrade) {
            return false;
        }

        if (! empty($transaction->product_id) && (string) $transaction->product_id === (string) ($latestUpgrade->sub_id ?? '')) {
            return true;
        }

        $transactionName = strtolower(trim((string) ($transaction->product_name ?? '')));
        $planLabel = strtolower(trim((string) ($subscription['plan_label'] ?? '')));
        if ($transactionName !== '' && $planLabel !== '' && $transactionName === $planLabel) {
            return true;
        }

        $upgradeInterval = strtoupper(trim((string) ($latestUpgrade->interval ?? '')));
        $transactionInterval = strtoupper(trim((string) ($transaction->frequency ?? '')));
        if ($upgradeInterval !== '' && $transactionInterval !== '' && $upgradeInterval === $transactionInterval) {
            return true;
        }

        $billingInterval = strtoupper(trim((string) data_get($transaction, 'billing.interval', '')));
        if ($upgradeInterval !== '' && $billingInterval !== '' && $upgradeInterval === $billingInterval) {
            return true;
        }

        return false;
    }

    protected function resolveSubscriptionBillingInterval($latestUpgrade, $transaction): ?string
    {
        foreach ([
            $latestUpgrade->interval ?? null,
            $transaction->frequency ?? null,
            data_get($transaction, 'billing.interval'),
            data_get($transaction, 'billing.period'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtolower(trim($candidate));
            }
        }

        if ($latestUpgrade && strtolower((string) ($latestUpgrade->pay_mode ?? '')) === 'one_time') {
            return 'lifetime';
        }

        return null;
    }

    /** @var Collection<int, covariant \Illuminate\Database\Eloquent\Model>|null */
    private ?Collection $plansMemo = null;

    protected function subscriptionPlans(): Collection
    {
        // Memoised on the controller instance (one per request), never in a static: a plan edited in another request must
        // show in the next one even in a long-lived worker (Octane).
        return $this->plansMemo ??= app(PlanCatalog::class)->plansByPosition();
    }

    protected function findTargetUserForManifest(array $userData): ?User
    {
        $email = trim((string) ($userData['email'] ?? ''));
        $username = trim((string) ($userData['username'] ?? ''));

        if ($email !== '') {
            if ($user = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $email))])->first()) {
                return $user;
            }
        }

        if ($username !== '') {
            if ($user = User::where('username', $username)->first()) {
                return $user;
            }
        }

        return null;
    }

    protected function createUserFromManifest(array $userData): User
    {
        $record = $this->prepareUserRecordForInsert($userData);

        $user = new User;
        $user->forceFill($record);
        $user->save();

        return $user;
    }

    protected function prepareUserRecordForInsert(array $userData): array
    {
        $record = $this->filterRowForInsert(LegacyMoneyColumns::upgradeRow('users', $userData, platform_currency()), 'users', ['id', 'remember_token']); // P1-05: balance
        unset(
            $record['subscription'],
            $record['options'],
            $record['upgrades'],
            $record['transactions'],
            $record['blog_comments']
        );

        $record['user_type'] = $record['user_type'] ?? 'user';
        $record['group_id'] = $record['group_id'] ?? 'free';
        $record['name'] = $record['name'] ?? ($record['username'] ?? 'Imported User');
        $record['username'] = $record['username'] ?? ('import-'.Str::random(8));
        $record['email'] = $record['email'] ?? ($record['username'].'@import.local');
        $record['password'] = $record['password'] ?? Hash::make(Str::random(32));
        $record['image'] = $record['image'] ?? 'default_user.png';
        $record['status'] = $record['status'] ?? 1;
        $record['created_at'] = $record['created_at'] ?? now()->toDateTimeString();
        $record['updated_at'] = $record['updated_at'] ?? now()->toDateTimeString();

        return $record;
    }

    protected function filterRowForInsert(array $row, string $table, array $ignoredKeys = []): array
    {
        unset($row['id'], $row['upgrade_id']);
        // P1-04 slice 5: an archive's tenant keys are never trusted — account_id (users + every tenant table)
        // and a child row's copied store_id are recomputed for the restoring owner (TenantFill::row()).
        if ($table === 'users' || TenantTables::get($table) !== null) {
            unset($row['account_id']);
        }
        if (isset(TenantTables::parents()[$table])) {
            unset($row['store_id']);
        }
        foreach ($ignoredKeys as $ignoredKey) {
            unset($row[$ignoredKey]);
        }

        return $row;
    }

    /**
     * P1-06: a restored priced variant's `options` cell ({"<option group id>": option index}) re-keyed to the restored
     * groups' new ids. Matrix variants ({"Size": "M"}) and unknown ids are left as they are.
     *
     * @param  array<int, int>  $groupMap  archived group id => restored group id
     */
    protected function remapVariantGroupKeys(mixed $options, array $groupMap): mixed
    {
        $decoded = is_string($options) ? json_decode($options, true) : (is_object($options) ? (array) $options : $options);
        if (! is_array($decoded) || $decoded === [] || $groupMap === []) {
            return $options;
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[is_numeric($key) && isset($groupMap[(int) $key]) ? (string) $groupMap[(int) $key] : (string) $key] = $value;
        }

        return json_encode((object) $out);
    }

    protected function extractIds($values): array
    {
        return collect($values)->map(fn ($value) => (int) $value)->filter()->values()->all();
    }

    protected function exportRestaurantOrders(int $storeId): array
    {
        $orders = DB::table('orders')
            ->where('store_id', $storeId)
            ->orderBy('id')
            ->get();

        $exported = [];
        foreach ($orders as $orderRow) {
            $order = (array) $orderRow;
            $orderItems = DB::table('order_items')
                ->where('order_id', (int) $order['id'])
                ->orderBy('id')
                ->get();

            $items = [];
            foreach ($orderItems as $orderItemRow) {
                $orderItem = (array) $orderItemRow;
                $itemExtras = DB::table('order_item_extras')
                    ->where('order_item_id', (int) $orderItem['id'])
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($row) => (array) $row)
                    ->all();

                $items[] = [
                    'item' => $orderItem,
                    'extras' => $itemExtras,
                ];
            }

            $exported[] = [
                'order' => $order,
                'items' => $items,
            ];
        }

        return $exported;
    }

    protected function summarizeBackupManifest(array $manifest): string
    {
        $user = $manifest['user'] ?? [];
        $blogs = $manifest['blogs'] ?? [];
        $stores = $manifest['stores'] ?? [];
        $blogCount = count($blogs);
        $restaurantCount = count($stores);
        $categoryCount = 0;
        $menuCount = 0;
        $extraCount = 0;
        $variantCount = 0;
        $variantOptionCount = 0;
        $imageMenuCount = 0;
        $orderCount = 0;
        $orderItemCount = 0;
        $orderItemExtraCount = 0;

        foreach ($stores as $restaurantBackup) {
            $categoryCount += count($restaurantBackup['categories'] ?? []);
            $menuCount += count($restaurantBackup['menus'] ?? []);
            $extraCount += count($restaurantBackup['menu_extras'] ?? []);
            $variantCount += count($restaurantBackup['menu_variants'] ?? []);
            $variantOptionCount += count($restaurantBackup['menu_variant_options'] ?? []);
            $imageMenuCount += count($restaurantBackup['image_menus'] ?? []);
            $orderCount += count($restaurantBackup['orders'] ?? []);

            foreach ($restaurantBackup['orders'] ?? [] as $orderBackup) {
                foreach ($orderBackup['items'] ?? [] as $itemBackup) {
                    $orderItemCount++;
                    $orderItemExtraCount += count($itemBackup['extras'] ?? []);
                }
            }
        }

        $lines = [
            // F3: manifest values come from an uploaded (owner-supplied) backup
            // and this summary is echoed raw in the admin panel — escape them.
            'Backup preview for '.e((string) ($user['name'] ?? 'user #'.($user['id'] ?? 'unknown'))),
            'Subscription: '.e((string) ($user['subscription']['plan_label'] ?? ($user['subscription']['group_id'] ?? 'n/a'))),
            'Blogs: '.number_format($blogCount),
            'Restaurants: '.number_format($restaurantCount),
            'Categories: '.number_format($categoryCount),
            'Menus: '.number_format($menuCount),
            'Extras: '.number_format($extraCount),
            'Variants: '.number_format($variantCount),
            'Variant options: '.number_format($variantOptionCount),
            'Image menus: '.number_format($imageMenuCount),
            'Orders: '.number_format($orderCount),
            'Order items: '.number_format($orderItemCount),
            'Order item extras: '.number_format($orderItemExtraCount),
            'Transactions: '.number_format(count($user['transactions'] ?? [])),
            'Blog comments: '.number_format(count($user['blog_comments'] ?? [])),
            'Subscription records: '.number_format(count($user['upgrades'] ?? [])),
            'User options: '.number_format(count($user['options'] ?? [])),
        ];

        return implode('<br>', $lines);
    }

    protected function writeImportReport(User $user, array $manifest, array $meta = []): string
    {
        $payload = [
            'meta' => array_merge([
                'user_id' => $user->id,
                'user_name' => $user->name,
                'generated_at' => now()->toDateTimeString(),
            ], $meta),
            'summary' => $this->summarizeBackupManifest($manifest),
            'manifest_version' => $manifest['version'] ?? null,
            'exported_at' => $manifest['exported_at'] ?? null,
            'user' => [
                'id' => $manifest['user']['id'] ?? null,
                'name' => $manifest['user']['name'] ?? null,
                'email' => $manifest['user']['email'] ?? null,
                'subscription' => $manifest['user']['subscription'] ?? null,
            ],
        ];

        $reportsDirectory = $this->backupReportsDirectory();
        $reportFile = 'import-report-user-'.$user->id.'-'.now()->format('Ymd-His').'.json';
        $reportPath = $reportsDirectory.DIRECTORY_SEPARATOR.$reportFile;

        file_put_contents(
            $reportPath,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        return $reportPath;
    }

    protected function writeBulkImportReport(array $payload): string
    {
        $reportsDirectory = $this->backupReportsDirectory();
        $reportFile = 'bulk-import-report-'.now()->format('Ymd-His').'-'.Str::random(8).'.json';
        $reportPath = $reportsDirectory.DIRECTORY_SEPARATOR.$reportFile;

        file_put_contents(
            $reportPath,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        return $reportPath;
    }

    protected function writeBulkExportStatus(string $token, array $status): string
    {
        $statusPath = $this->writeExportStatusToDirectory($this->bulkExportDirectory($token), $status);

        return $statusPath;
    }

    protected function writeExportStatusToDirectory(string $directory, array $status): string
    {
        $statusPath = $directory.DIRECTORY_SEPARATOR.'status.json';
        file_put_contents(
            $statusPath,
            json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        return $statusPath;
    }

    protected function shouldIncludeImage(?string $filename): bool
    {
        return ! empty($filename) && ! in_array($filename, ['default.png', 'default_user.png'], true);
    }

    protected function generateUniqueRestaurantTableKey(): string
    {
        do {
            $key = Str::random(13);
        } while (DB::table('store_tables')->where('key', $key)->exists());

        return $key;
    }

    protected function publicStoragePath(string $relativePath): string
    {
        return public_path('storage/'.ltrim($relativePath, '/'));
    }

    protected function backupTempDirectory(): string
    {
        $directory = storage_path('app/user-backups');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            abort(500, 'Unable to create backup working directory.');
        }

        return $directory;
    }

    protected function bulkExportDirectory(string $token): string
    {
        $directory = storage_path('app/user-backups/exports/'.$token);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            abort(500, 'Unable to create bulk export directory.');
        }

        return $directory;
    }

    protected function targetExportDirectory(string $token): string
    {
        $directory = storage_path('app/user-backups/target-exports/'.$token);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            abort(500, 'Unable to create target export directory.');
        }

        return $directory;
    }

    protected function hungerinoExportDirectory(string $token): string
    {
        $directory = storage_path('app/user-backups/hungerino-exports/'.$token);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            abort(500, 'Unable to create Hungerino export directory.');
        }

        return $directory;
    }

    protected function backupReportsDirectory(): string
    {
        $directory = storage_path('app/user-backups/reports');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            abort(500, 'Unable to create backup reports directory.');
        }

        return $directory;
    }

    protected function copyBackupFile(string $sourcePath, string $targetPath, array &$createdFiles): void
    {
        if (! is_file($sourcePath)) {
            return;
        }

        $targetDirectory = dirname($targetPath);
        if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0775, true) && ! is_dir($targetDirectory)) {
            throw new \RuntimeException('Unable to create backup restore directory.');
        }

        if (! copy($sourcePath, $targetPath)) {
            throw new \RuntimeException('Unable to restore file: '.basename($targetPath));
        }

        $createdFiles[] = $targetPath;
    }

    protected function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            if ($item->isDir()) {
                $this->removeDirectory($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    public function create(): View
    {
        return view('admin.users.create');
    }

    /** A merchant account made by the superadmin: verified at once, details mailed to the holder. */
    public function store(AccountStoreRequest $request): JsonResponse
    {
        $country = Country::findOrFail($request->integer('country'));
        $picture = $this->panelImage($request->file('avatar'), self::AVATAR_FOLDER, self::AVATAR_SIZE, null, self::AVATAR_PLACEHOLDER);

        $account = DB::transaction(function () use ($request, $country, $picture): User {
            $account = User::create([
                'user_type' => 'user',
                'name' => $request->input('name'),
                'username' => $request->input('username'),
                'email' => $request->input('email'),
                'password' => Hash::make((string) $request->input('password')),
                'image' => $picture,
                'group_id' => config('settings.default_user_plan') ?: 'free',
                'country_code' => $country->code,
                'country' => $country->name,
            ]);
            /* Plans rebuild R1 — the subscription row mirrors the new account's plan. */
            app(Subscriptions::class)->startFromRegistration($account);
            $account->markEmailAsVerified();

            return $account;
        });

        $account->sendMail(new UserDetails($account));

        return $this->panelDone(___('Created Successfully'));
    }

    /** Accounts have no read-only page; the edit screen is the detail view. */
    public function show(User $user): never
    {
        abort(404);
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', ['user' => $user]);
    }

    public function update(AccountUpdateRequest $request, User $user): RedirectResponse
    {
        $country = Country::findOrFail($request->integer('country'));

        $changes = [
            'user_type' => $request->input('user_type'),
            'name' => $request->input('name'),
            'username' => $request->input('username'),
            'email' => $request->input('email'),
            'country_code' => $country->code,
            'country' => $country->name,
            'image' => $this->panelImage($request->file('avatar'), self::AVATAR_FOLDER, self::AVATAR_SIZE, $user->image, self::AVATAR_PLACEHOLDER),
        ];
        if ($request->filled('status')) {
            $changes['status'] = $request->boolean('status') ? 1 : 0;
        }
        $user->update($changes);

        if ($request->filled('email_status')) {
            $verified = $request->boolean('email_status');
            if ($verified !== ($user->email_verified_at !== null)) {
                $user->forceFill(['email_verified_at' => $verified ? Carbon::now() : null])->save();
            }
        }

        app_alert_success(___('Updated Successfully'));

        return back();
    }

    /** Accounts are removed through the list's bulk action (delete()), never one by one here. */
    public function destroy(User $user): never
    {
        abort(404);
    }

    /**
     * One DataTables row of the accounts list (column order = the list's
     * <thead>). Every value from the account is escaped; `$planStart`,
     * `$planEnd` and `$lastLogin` are built by index() (dates and badges).
     *
     * @return array<int|string, mixed>
     */
    private function accountRow(User $account, string $planLabel, string $planStart, string $planEnd, string $lastLogin, string $waMessage): array
    {
        $editUrl = route('admin.users.edit', $account->id);
        $onHold = ! empty($account->abuse_hold);

        $state = $account->status
            ? Cells::badge(___('Active'), 'success')
            : Cells::badge(___('Banned'), 'danger');
        if ($onHold) {
            $state .= ' <span class="badge bg-warning" title="'.e(___('Writes + AI frozen; public menu stays live')).'">'.e(___('On Hold')).'</span>';
        }

        $identity = '<td><div class="d-flex align-items-center">'
            .'<a class="avatar me-2" href="'.e($editUrl).'"><img class="rounded-circle" src="'.e(asset(self::AVATAR_FOLDER.$account->image)).'" alt="'.e($account->username).'"></a>'
            .'<div><a class="text-body fw-semibold text-truncate" href="'.e($editUrl).'">'.e($account->name).'</a>'
            .'<p class="text-muted mb-0">@'.e($account->username).'</p></div>'
            .'</div></td>';

        $storePhone = (string) ($account->getAttribute('store_phone') ?? '');
        $phoneCell = $storePhone === ''
            ? Cells::text('-')
            : '<td><span class="text-truncate d-inline-block" style="max-width:140px">'.e($storePhone).'</span></td>';

        // Both WhatsApp cells open a chat with the renewal reminder pre-filled.
        // Branch numbers go through whatsapp_normalize_number(): a bare digit
        // filter would drop Arabic-Indic numerals.
        $storeWa = $storePhone === '' ? '' : (string) preg_replace('/\D+/', '', $storePhone);
        $branchNumber = (string) ($account->getAttribute('branch_whatsapp') ?? '');
        $branchWa = $branchNumber !== '' ? (string) whatsapp_normalize_number($branchNumber) : '';

        $signInAs = '<form action="'.e(route('admin.users.login', $account->id)).'" method="post"'
            .' data-impersonate="'.e(___('Why are you logging in as this user? (kept in the audit log)')).'">'.csrf_field()
            .'<input type="hidden" name="reason" value="">'
            .'<button class="btn btn-icon btn-default" title="'.e(___('Login as User')).'" data-tippy-placement="top"><i class="icon-feather-log-in"></i></button>'
            .'</form>';
        $holdToggle = '<form action="'.e(route('admin.users.abuseHold', $account->id)).'" method="post"'
            .' onsubmit="return confirm('.e(json_encode(___('Toggle abuse hold for this merchant? Their public menu stays live.'))).')">'.csrf_field()
            .'<button class="btn btn-icon '.($onHold ? 'btn-warning' : 'btn-default').'" title="'.e($onHold ? ___('Lift abuse hold') : ___('Place on abuse hold')).'" data-tippy-placement="top"><i class="icon-feather-shield"></i></button>'
            .'</form>';

        return [
            Cells::text((string) $account->id),
            $identity,
            '<td><span class="text-truncate">'.e($account->email).'</span></td>',
            Cells::html($account->email_verified_at ? Cells::badge(___('Verified'), 'success') : Cells::badge(___('Unverified'), 'warning')),
            Cells::html($state),
            Cells::text($planLabel),
            Cells::html($planStart),
            Cells::html($planEnd),
            Cells::text($lastLogin),
            Cells::text(number_format((int) ($account->stores_count ?? 0))),
            $phoneCell,
            Cells::html($this->whatsAppLink($storeWa, $storePhone, $waMessage, 'text-success')),
            Cells::html($this->whatsAppLink($branchWa, $branchNumber, $waMessage, 'text-primary')),
            Cells::text(number_format((int) ($account->transactions_count ?? 0))),
            Cells::text(date_formating($account->created_at)),
            Cells::actions(Cells::iconLink($editUrl, 'icon-feather-edit', ___('Edit')), $signInAs, $holdToggle),
            Cells::checkbox($account->id),
            'DT_RowId' => $account->id,
        ];
    }

    /** wa.me chat link, or a dash when there is no number. */
    private function whatsAppLink(string $digits, string $label, string $text, string $tone): string
    {
        if ($digits === '') {
            return '-';
        }

        return '<a href="'.e('https://wa.me/'.$digits.'?text='.rawurlencode($text)).'" target="_blank" rel="noopener" class="'.$tone.'">'
            .'<i class="icon-brand-whatsapp me-1"></i>'.e($label).'</a>';
    }

    protected function usersTableQuery(Request $request): Builder
    {
        $prefix = DB::getTablePrefix();
        $planTable = $prefix.'plans';

        /* R3 (plans rebuild): plan columns come from the latest subscriptions row.
           Shapes preserved for the row renderers: unix ints for plan_start/plan_end,
           lifetime emitted as >4e9 (legacy sentinel), UPPER-cased interval. plan_key
           is 'trial' | plan-id | 'free' (NULL when no row → legacy fallback). */
        $subTable = $prefix.'subscriptions';
        $latestSub = "SELECT %s FROM {$subTable} WHERE {$subTable}.user_id = {$prefix}users.id ORDER BY id DESC LIMIT 1";

        /* Driver-portable SQL (MySQL 8.4 / PostgreSQL 18 / sqlite tests) via Juwri\Core\Support\Sql. */
        $ux = fn (string $col): string => Sql::epoch($col);
        $planEndExpr = "CASE WHEN ends_at IS NULL AND interval_ = 'lifetime' THEN 8000000000 "
            ."WHEN status = 'expired' AND (ends_at IS NULL OR ends_at > CURRENT_TIMESTAMP) THEN ".$ux('updated_at').' '
            .'ELSE '.$ux('ends_at').' END';
        // P1-07: the trial length comes from the typed platform setting (was a JSON_EXTRACT
        // sub-select on the removed `options` table); bound as a literal column.
        $trialDays = PlatformSettings::array('trial_membership_plan')['days'] ?? null;
        $trialDays = is_numeric($trialDays) ? (string) (int) $trialDays : 'NULL';

        $query = User::query()->select('users.*');
        // P1-13 13i: the subscriptions table belongs to Billing (Enterprise package). Without it (Community install
        // without the package) the plan columns are NULL — the row renderers' "no subscription row" case.
        if (Schema::hasTable('subscriptions')) {
            $query->selectRaw('('.sprintf($latestSub, "CASE WHEN interval_ = 'trial' THEN 'trial' WHEN plan_id IS NOT NULL THEN ".Sql::castText('plan_id')." ELSE 'free' END").') as plan_key')
                ->selectRaw('('.sprintf($latestSub, $ux('starts_at')).') as plan_start')
                ->selectRaw('('.sprintf($latestSub, $planEndExpr).') as plan_end')
                ->selectRaw('('.sprintf($latestSub, 'UPPER(interval_)').') as plan_interval')
                ->selectRaw('('.sprintf($latestSub, 'status').') as plan_sub_status');
        } else {
            $query->selectRaw('NULL as plan_key, NULL as plan_start, NULL as plan_end, NULL as plan_interval, NULL as plan_sub_status');
        }
        $query
            ->selectRaw("{$trialDays} as trial_days")
            ->selectRaw("(SELECT phone FROM {$prefix}stores WHERE user_id = {$prefix}users.id LIMIT 1) as store_phone")
            // WhatsApp numbers moved from the purged whatsapp_number post option
            // onto branches (PR #425) — read the first store's first branch that
            // has one, mirroring storefront_whatsapp_number()'s default-branch
            // order (position, then id).
            ->selectRaw("(SELECT b.whatsapp FROM {$prefix}branches b JOIN {$prefix}stores p ON p.id = b.store_id WHERE p.user_id = {$prefix}users.id AND b.whatsapp IS NOT NULL AND b.whatsapp != '' ORDER BY p.id, b.position, b.id LIMIT 1) as branch_whatsapp")
            ->withCount([
                'stores as stores_count',
                'transactions as transactions_count',
            ]);

        $this->applyUserFilters($query, $request);

        return $query;
    }

    protected function applyUserFilters(Builder $query, Request $request): void
    {
        if ($request->filled('plan')) {
            /* R2 (plans rebuild): plan filter reads subscriptions, not group_id (P1-13 13g: Billing's read model). */
            app(Subscriptions::class)->filterPlan($query, (string) $request->input('plan'));
        }

        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        if ($request->filled('restaurants')) {
            if ((string) $request->input('stores') === '0') {
                $query->whereDoesntHave('stores');
            } elseif ((string) $request->input('stores') === '1') {
                $query->has('stores');
            }
        }

        if ($request->filled('email_status')) {
            if ($request->input('email_status') === 'verified') {
                $query->whereNotNull('email_verified_at');
            } elseif ($request->input('email_status') === 'unverified') {
                $query->whereNull('email_verified_at');
            }
        }

        if ($request->filled('plan_status')) {
            /* R2 (plans rebuild): all plan_status branches read subscriptions (P1-13 13g: Billing's read model). */
            app(Subscriptions::class)->filterPlanStatus($query, (string) $request->input('plan_status'));
        }

        // Accept both the DataTables ajax shape (search[value]=…) and the flat
        // server-rendered / global-header shape (?search=…). Reading both here
        // means we never rewrite the request bag into an array, which would
        // otherwise poison request('search') for any view that echoes it.
        $searchInput = $request->input('search');
        $search = SearchTerm::clean((string) (is_array($searchInput) ? ($searchInput['value'] ?? '') : $searchInput));
        if ($search !== '') {
            // Driver-portable date formatting for the free-text date search.
            $fmt = fn (string $col): string => Sql::dateTimeText($col);
            // P6-101: literal %/_ (explicit ESCAPE), length-capped term.
            $like = SearchTerm::like($search);
            $query->where(function (Builder $searchQuery) use ($search, $fmt, $like) {
                $searchQuery->whereRaw(Sql::castText('id')." like ? escape '!'", [$like])
                    ->orWhere(fn (Builder $w) => SearchTerm::apply($w, ['name', 'username', 'email', 'country', 'group_id'], $search))
                    ->orWhereRaw($fmt('lastactive')." like ? escape '!'", [$like])
                    ->orWhereRaw($fmt('created_at')." like ? escape '!'", [$like]);
            });
        }
    }

    protected function applyUsersOrdering(Builder $query, Request $request): void
    {
        $orderColumn = (int) $request->input('order.0.column', 8);
        $orderDir = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $columns = [
            0 => 'id',
            1 => 'name',
            2 => 'email',
            3 => 'email_verified_at',
            4 => 'status',
            5 => 'plan_label',
            6 => 'plan_start',
            7 => 'plan_end',
            8 => 'lastactive',
            9 => 'stores_count',
            10 => 'store_phone',
            11 => 'store_phone',
            12 => 'branch_whatsapp',
            13 => 'transactions_count',
            14 => 'created_at',
        ];

        if (isset($columns[$orderColumn])) {
            $query->orderBy($columns[$orderColumn], $orderDir);
            if ($columns[$orderColumn] !== 'id') {
                $query->orderByDesc('id');
            }
        } else {
            $query->orderByDesc('lastactive')->orderByDesc('created_at')->orderByDesc('id');
        }
    }

    protected function userPlanLabel(User $user, $plans): string
    {
        /* R3 (plans rebuild): label follows the latest subscription row when one
           exists (plan_key select or lookup); legacy group_id protocol otherwise. */
        $key = $user->plan_key ?? null;
        if ($key === null) {
            $latest = app(PlanCatalog::class)->lastSubscription($user);
            if ($latest) {
                $key = $latest->getAttribute('interval_') === 'trial' ? 'trial' : ($latest->getAttribute('plan_id') ? (string) $latest->getAttribute('plan_id') : 'free');
            } else {
                $key = (string) $user->group_id;
            }
        }
        $key = (string) $key;

        if ($key === 'free') {
            return ___('Free');
        }

        if ($key === 'trial') {
            return ___('Trial');
        }

        $plan = $plans->firstWhere('id', $key);
        if (! $plan) {
            return $key;
        }

        return ! empty($plan->translations->{get_lang()}->name)
            ? $plan->translations->{get_lang()}->name
            : $plan->name;
    }

    /**
     * Bulk delete from the list: `ids` as an array or a comma-separated string.
     * An empty selection is refused with 422 (the list script shows the message).
     */
    public function delete(Request $request): JsonResponse
    {
        $selected = $request->input('ids', []);
        $ids = collect(is_string($selected) ? explode(',', $selected) : (array) $selected)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return response()->json(['success' => false, 'message' => ___('No users selected')], 422);
        }

        $skipped = $this->deleteUsersByIds($ids, $request->boolean('force'));
        if ($skipped !== []) {
            return response()->json([
                'success' => count($skipped) < count($ids),
                'skipped' => $skipped,
                'message' => ___(':count account(s) were not deleted: they still have money or a live paid subscription. Review them first.', ['count' => count($skipped)]),
            ], count($skipped) < count($ids) ? 200 : 422);
        }

        return $this->panelDone(___('Deleted Successfully'));
    }

    /**
     * Delete all users matching the current filters.
     *
     * @return JsonResponse
     */
    public function deleteFiltered(Request $request)
    {
        $query = $this->usersTableQuery($request);
        $ids = $query->pluck('id')->all();

        if (empty($ids)) {
            return response()->json([
                'success' => false,
                'message' => ___('No users found'),
            ], 422);
        }

        $ids = array_map('intval', $ids);

        // P6-119e: queued everywhere (LiteSpeed has no fastcgi_finish_request). The same selection is never queued twice.
        $token = DeleteUsersJob::tokenFor($ids);
        if (DeleteUsersJob::markQueued($token, count($ids))) {
            DeleteUsersJob::dispatch($token, $ids, (int) auth()->id() ?: null);
        }

        return response()->json([
            'success' => true,
            'queued' => true,
            'token' => $token,
            'total' => count($ids),
            'status_url' => route('admin.users.deleteFilteredStatus', $token),
            'message' => ___('Deleting users in the background'),
        ], 202);
    }

    /** P6-119e: progress of a queued filtered delete (admin routes only; the token is not guessable). */
    public function deleteFilteredStatus(string $token): JsonResponse
    {
        $status = preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? DeleteUsersJob::status($token) : null;
        if ($status === null) {
            return response()->json(['success' => false, 'message' => ___('Not found')], 404);
        }

        return response()->json(['success' => true] + $status);
    }

    /**
     * Entry point of {@see DeleteUsersJob}: the ids were chosen (and the admin checked) by the controller action.
     *
     * @param  list<int>  $ids
     * @return int accounts kept (P6-245)
     */
    public function deleteUsersInBackground(array $ids, ?int $adminId = null): int
    {
        return count($this->deleteUsersByIds(array_map('intval', $ids), false, $adminId));
    }

    /**
     * Return matching user IDs for the current filters.
     *
     * @return JsonResponse
     */
    public function selectionIds(Request $request)
    {
        $query = $this->usersTableQuery($request);

        $ids = $query->pluck('id')->all();

        return response()->json([
            'success' => true,
            'count' => count($ids),
            'ids' => array_map('intval', $ids),
        ], 200);
    }

    /**
     * Delete users and their related records.
     */
    /**
     * P6-245: a paying account is cancelled at the gateway before it is deleted (a failed cancel keeps the account —
     * the gateway would bill a deleted user for ever), and an account still holding money (wallet balance in any
     * currency, a pending seller withdrawal) is skipped unless $force. Every deleted account is written to the
     * activity log with the admin who deleted it.
     *
     * @param  list<int>  $ids
     * @return array<int, string> skipped account id => reason
     */
    protected function deleteUsersByIds(array $ids, bool $force = false, ?int $adminId = null): array
    {
        $accounts = User::query()->whereIn('id', $ids)->get();
        $skipped = [];
        $closer = app(AccountDeletionService::class);

        foreach ($accounts as $user) {
            if (! $force && ($reason = $this->moneyHeldBy($user)) !== null) {
                $skipped[(int) $user->id] = $reason;

                continue;
            }
            if ($closer->gatewaySubscriptionLive($user) && ! $closer->cancelAtGateway($user)) {
                $skipped[(int) $user->id] = 'gateway subscription could not be cancelled';

                continue;
            }
        }
        $accounts = $accounts->reject(fn (User $user): bool => isset($skipped[(int) $user->id]));

        foreach ($accounts as $user) {
            log_activity('admin.user_deleted', null, [
                'user_id' => (int) $user->id, 'email_hash' => hash('sha256', mb_strtolower((string) $user->email)),
                'forced' => $force, 'admin_id' => $adminId ?? auth()->id(),
            ], null);
            $this->dropPanelImage($user->image, self::AVATAR_FOLDER, self::AVATAR_PLACEHOLDER);

            // Stores go through the model so their own delete hooks run.
            Store::query()->where('user_id', $user->id)->get()->each(fn (Store $store) => $store->delete());

            // The "new account" admin notices point at this account's edit page.
            Notification::query()->where('link', route('admin.users.edit', $user->id))->get()->each->delete();

            /* F5-4: commercial/platform cascade (see method docblock). */
            $this->cascadePlatformRecords($user);
            AccountDeletionService::detachTrialClaims((int) $user->id); // P6-276
        }

        /* P6-246: an account with paid payments is anonymised, never deleted (receipts, tax and dispute records must
           keep pointing at it); every other one is deleted, its payments first stamped with an anonymised ref. */
        $paid = Transaction::query()->whereIn('user_id', $accounts->pluck('id')->all())
            ->where(fn ($q) => $q->whereIn('status', [Transaction::STATUS_SUCCESS, Transaction::STATUS_CONSUMED, 'refunded'])->orWhere('transaction_method', 'refund'))
            ->distinct()->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        foreach ($accounts as $user) {
            if (in_array((int) $user->id, $paid, true)) {
                $closer->anonymizeUser($user);
                log_activity('admin.user_anonymised', null, ['user_id' => (int) $user->id, 'admin_id' => $adminId ?? auth()->id()], null);
            } else {
                AccountDeletionService::stampFormerUserRef((int) $user->id);
            }
        }
        User::whereIn('id', $accounts->pluck('id')->reject(fn ($id) => in_array((int) $id, $paid, true))->values()->all())->delete();

        foreach ($skipped as $id => $reason) {
            log_activity('admin.user_delete_skipped', null, ['user_id' => $id, 'reason' => $reason, 'admin_id' => $adminId ?? auth()->id()], null);
        }

        return $skipped;
    }

    /** P6-245: why this account must not be deleted without force (money it still holds), or null. */
    private function moneyHeldBy(User $user): ?string
    {
        foreach (WalletService::balancesByCurrency($user) as $code => $balance) {
            if ($balance > 0) {
                return "wallet balance {$balance} {$code}";
            }
        }
        if (SellerWithdrawal::where('user_id', $user->id)->where('status', 'pending')->exists()) {
            return 'pending seller withdrawal';
        }

        return null;
    }

    /**
     * F5-4 (feature-platform gap-fix): hard-deleting a user used to leave six
     * classes of live commercial rows pointing at a user id that no longer
     * exists — entitlement_overrides, user_addons, app_installations,
     * subscriptions, OAuth tokens and webhook endpoints. Orphaned overrides and
     * add-ons resurrect as grants the moment the id is reused; orphaned OAuth
     * tokens and webhook endpoints leave a partner with live API access and a
     * live delivery target for a tenant that is gone.
     *
     * Installations are UNINSTALLED first (AppInstallService::uninstall — the
     * one implementation) so the partner is told through the signed webhook
     * rails that their install is gone, their OAuth tokens are revoked and
     * their feature grants are withdrawn, before the rows themselves go.
     *
     * Deliberately NOT deleted: `wallet_transactions`, `partner_earnings` and
     * every other money record. Deleting a user must not erase the ledger.
     * Two pre-existing schema cascades are outside this method's control and
     * are flagged rather than worked around: `transaction.user_id` has an
     * ON DELETE CASCADE to `user` (so the gateway ledger for that user has
     * always gone with them), and `app_usage_records.app_installation_id`
     * cascades with the installation rows removed below (the money for that
     * usage survives in the wallet ledger and partner_earnings).
     */
    protected function cascadePlatformRecords(User $user): void
    {
        $userId = (int) $user->id;

        /* 1. App installations — notify the partner, then remove. */
        try {
            $installService = app(AppInstallService::class);
            $installations = AppInstallation::where('user_id', $userId)->get();
            foreach ($installations as $installation) {
                if ($installation->status !== 'uninstalled') {
                    try {
                        $installService->uninstall($installation);
                        /* uninstall() QUEUES app.uninstalled, but this request goes
                           on to delete the endpoint row the queued job needs — so
                           make one guaranteed attempt inline before it disappears.
                           Delivery is at-least-once by contract (Standard Webhooks
                           message id), so a racing queued copy is harmless. */
                        $this->deliverUninstallNotice($installation->fresh());
                    } catch (\Throwable $e) {
                        \Log::warning('[user-delete] uninstall failed for installation '
                            .$installation->id.': '.$e->getMessage());
                    }
                }
                $installation->delete();
            }
        } catch (\Throwable $e) {
            \Log::warning('[user-delete] app installation cascade failed: '.$e->getMessage());
        }

        /* 2. Entitlement grants and paid add-ons. */
        foreach ([
            EntitlementOverride::class,
        ] as $model) {
            try {
                $model::where('user_id', $userId)->delete();
            } catch (\Throwable $e) {
                \Log::warning('[user-delete] cascade failed for '.$model.': '.$e->getMessage());
            }
        }
        app(Subscriptions::class)->forgetAccount((int) $userId); // paid add-ons + subscriptions (Billing)

        /* 3. OAuth grants (Passport): access tokens + their refresh chain and
              any outstanding auth codes. Table names come from Passport's own
              models so a table prefix is applied by the connection. */
        try {
            $accessTokenIds = Token::where('user_id', $userId)->pluck('id');
            if ($accessTokenIds->isNotEmpty()) {
                RefreshToken::whereIn('access_token_id', $accessTokenIds)->delete();
            }
            Token::where('user_id', $userId)->delete();
            AuthCode::where('user_id', $userId)->delete();
        } catch (\Throwable $e) {
            \Log::warning('[user-delete] oauth cascade failed: '.$e->getMessage());
        }

        /* 4. Webhook endpoints (own + per-install) and owner API tokens — both
              are live access/delivery surfaces for a tenant that is gone.
              `webhook_deliveries` rows are an attempt LOG (no FK) and stay. */
        try {
            WebhookEndpoint::where('user_id', $userId)->delete();
            ApiToken::where('user_id', $userId)->delete();
        } catch (\Throwable $e) {
            \Log::warning('[user-delete] webhook/api-token cascade failed: '.$e->getMessage());
        }
    }

    /**
     * F5-4: one inline app.uninstalled attempt for an installation whose
     * webhook endpoint is about to be deleted with its owner.
     *
     * @param  AppInstallation|null  $installation
     */
    protected function deliverUninstallNotice($installation): void
    {
        if (! $installation || ! $installation->webhook_endpoint_id) {
            return;
        }

        try {
            Bus::dispatchSync(new DeliverWebhookJob(
                (int) $installation->webhook_endpoint_id,
                'app.uninstalled',
                [
                    'app' => $installation->app?->slug,
                    'user_id' => (int) $installation->user_id,
                    'uninstalled_at' => now()->toIso8601String(),
                    'reason' => 'account_deleted',
                ],
                'msg_'.Str::random(24),
                now()->getTimestamp(),
            ));
        } catch (\Throwable $e) {
            \Log::warning('[user-delete] uninstall webhook delivery failed: '.$e->getMessage());
        }
    }

    /** Put the shared placeholder back and drop the account's own picture file. */
    public function deleteAvatar(User $user): RedirectResponse
    {
        $this->dropPanelImage($user->image, self::AVATAR_FOLDER, self::AVATAR_PLACEHOLDER);
        $user->update(['image' => self::AVATAR_PLACEHOLDER]);

        app_alert_success(___('Removed Successfully'));

        return back();
    }

    public function password(User $user): View
    {
        return view('admin.users.password', ['user' => $user]);
    }

    public function updatePassword(AccountPasswordRequest $request, User $user): RedirectResponse
    {
        $user->update(['password' => Hash::make((string) $request->input('password'))]);

        /* Audited like impersonation (#83): a superadmin who sets a merchant's
           password can then sign in as them, so the reset must leave a trace.
           The password itself is never logged. */
        log_activity('admin.password_reset', $user, [
            'admin_id' => $request->user()?->id,
            'target_id' => $user->id,
            'target_name' => $user->name,
            'ip' => $request->ip(),
        ]);

        app_alert_success(___('Password changed successfully'));

        return back();
    }

    /** A one-off message to the account holder, queued after the request's transaction. */
    public function sendMail(AccountMailRequest $request, User $user): RedirectResponse
    {
        $message = new SupportMessage(
            (string) $request->input('subject'),
            (string) $request->input('message'),
            [],
            (string) $request->input('reply_to'),
        );

        try {
            Mail::to($user->email)->queue($message->afterCommit());
        } catch (\Throwable $e) {
            report($e);
            app_alert_error(___('Error in sending email').' '.$e->getMessage());

            return back();
        }

        app_alert_success(___('Sent successfully'));

        return back();
    }

    /**
     * Manual plan change (P1-13 13g: Billing's {@see self::PLAN_UPDATE_SLOT}; the route is Billing's too). The legacy
     * membership row (`upgrades`) is replaced — none for the free plan — then the subscription mirror is resynced.
     */
    public function updatePlan(Request $request, User $user): RedirectResponse
    {
        $response = Hooks::answer(self::PLAN_UPDATE_SLOT, $request, $user);
        abort_unless($response instanceof RedirectResponse, 404);

        return $response;
    }

    /**
     * Impersonation: the superadmin continues as the account holder; the admin
     * id stays in the session so logging out returns to the admin account.
     */
    public function loginAsUser(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        if ($admin === null || $admin->is($user)) {
            app_alert_info(___('Already logged in as :user_name.', ['user_name' => '<strong>'.e($user->name).'</strong>']));

            return back();
        }

        /* An admin account is only ever used by its holder, with its own
           sign-in and 2FA: never opened through another admin. */
        if ($user->isAdmin()) {
            app_alert_error(___('You cannot log in as another admin.'));

            return back();
        }

        /* Every impersonation states why (kept in the audit log). */
        $reason = trim(strip_tags((string) $request->input('reason', '')));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
            app_alert_error(___('Give a short reason (5–200 characters) to log in as this user.'));

            return back();
        }

        /* #83: written before the switch so the actor is the admin. */
        log_activity('admin.impersonate', $user, [
            'admin_id' => $admin->id,
            'target_id' => $user->id,
            'target_name' => $user->name,
            'reason' => $reason,
            'ip' => $request->ip(),
        ]);

        Auth::logout();
        Auth::login($user);
        $request->session()->put('quick_admin_user_id', $admin->id);
        /* AdminImpersonationGuard ends the session after an hour. */
        $request->session()->put('quick_admin_impersonation', ['started_at' => now()->timestamp, 'target_id' => $user->id, 'reason' => $reason]);

        app_alert_success(___('You are logged in as :user_name.', ['user_name' => $user->name]));

        return redirect()->route('dashboard');
    }

    /* P4 (plans rebuild, decision E): admin-only seasonal pause/resume (P1-13 13g: Billing's slots and routes). */
    public function pauseSubscription($userId)
    {
        $response = Hooks::answer(self::SUBSCRIPTION_PAUSE_SLOT, User::findOrFail($userId));
        abort_unless($response instanceof RedirectResponse, 404);

        return $response;
    }

    public function resumeSubscription($userId)
    {
        $response = Hooks::answer(self::SUBSCRIPTION_RESUME_SLOT, User::findOrFail($userId));
        abort_unless($response instanceof RedirectResponse, 404);

        return $response;
    }

    /**
     * Abuse Guard P6 (6.3): toggle a merchant's abuse hold. Freezes their dashboard
     * writes + AI; their public QR menu stays live (read-only).
     */
    public function toggleAbuseHold($userId)
    {
        $user = User::findOrFail($userId);
        $on = ! (bool) $user->abuse_hold;
        AbuseHold::set($user->id, $on);
        AbuseGuard::hit('hold', $on ? 'placed' : 'lifted', ['user_id' => $user->id]);

        app_alert_success($on ? ___('Merchant placed on abuse hold.') : ___('Abuse hold lifted.'));

        return back();
    }
}
