<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P6-102: store-facing reports, exports and daily jobs must bucket by the STORE calendar day (App\Support\StoreClock),
 * not the UTC app clock. This test scans app/ for UTC-day idioms (whereDate on *_at columns, Carbon::today(),
 * now()->startOfDay()/toDateString(), bare today()). Files below are reviewed UTC-correct or platform-level and
 * are allowlisted with a reason. SHRINK-ONLY: a new offender fails, and an allowlisted file that no longer matches
 * must be removed from the list.
 */

$platform = 'platform/admin metric or abuse/cost guard, UTC is the correct clock';
$pending = 'store-facing, still UTC-based: migrate to StoreClock (P6-102 follow-up)';
$dateCol = 'compares a DATE (not a timestamp) column or stores a plain date; no timezone shift involved';

$allowlist = [
    'app/Http/Controllers/User/DashboardController.php' => 'only a docblock mention; the dashboard already uses the store-tz todayWindow',
    'app/Http/Helpers/StoreMoneyHelper.php' => 'only a docblock mention (this is the store_tz helper)',
    'app/Core/Models/TenantUsageDaily.php' => $platform,
    'app/Core/Services/AbuseMonitor.php' => $platform,
    'app/Core/Services/AlertDispatcher.php' => $platform,
    'app/Core/Console/Commands/PlatformMediaBackup.php' => $platform,
    'app/Support/SchedulerAlert.php' => $platform,
    'app/Http/Controllers/Admin/AbuseGuardController.php' => $platform,
    'app/Services/AdminDashboardService.php' => $platform,
    'app/Services/PlatformMetrics.php' => $platform,
    'app/Services/FeatureSpotlight.php' => 'plan/trial countdown, platform day',
    'app/Models/AppInstallation.php' => 'trial countdown, platform day',
    'app/Modules/AppStore/Console/Commands/AppDunning.php' => 'platform billing dunning, UTC',
    'app/Modules/Ai/Services/AiSpendGuard.php' => $platform,
    'app/Modules/Messaging/Services/MessagingGuard.php' => $platform,
    'app/Modules/Loyalty/Http/Controllers/User/StoreCouponController.php' => $platform,
    'app/Modules/Storefront/Http/Controllers/Admin/MenuTemplateController.php' => 'platform admin listing',
    'app/Modules/Storefront/Http/Controllers/User/ThemeStudioAiController.php' => 'daily AI quota guard',
    'app/Modules/Analytics/Console/Commands/RollupStorePageViews.php' => 'platform retention prune',
    'app/Modules/Accounting/Services/Accounting/ExpenseService.php' => $pending,
    'app/Modules/Accounting/Console/Commands/AccountingBackfill.php' => 'one-off backfill command',
    'app/Modules/Hr/Console/Commands/StaffDocumentsExpiryAlert.php' => $dateCol,
    'app/Modules/Menu/Models/Menu.php' => $dateCol,
    'app/Modules/Pharmacy/Models/PharmacyLicence.php' => $dateCol,
    'app/Modules/Integrations/Services/Odoo/OdooSync.php' => 'invoice date sent to external ERP',
    'app/Modules/Tables/Services/TableSessionService.php' => $pending,
    'app/Modules/Biolink/Services/BioLinkService.php' => 'public link analytics day',
    'app/Modules/Storefront/Http/Controllers/FolioMetricsController.php' => 'public analytics day',
    'app/Modules/Storefront/Http/Helpers/FolioHelper.php' => 'public analytics day',
    'app/Modules/Storefront/Storefront/Drops/HoursDrop.php' => 'method named today(), not a clock read',
    'app/Core/Services/LangPipelineService.php' => 'method named today(), platform',
    'app/Modules/Analytics/Console/Commands/SendAnomalyAlerts.php' => 'per-user scan-view alert over a date column, platform job',
    'app/Modules/Analytics/Http/Controllers/User/AnalyticsController.php' => 'fallback branch only; main path already uses store tz',
    'app/Modules/Hr/Http/Controllers/User/RotaController.php' => $pending,
    'app/Modules/Hr/Services/Rota/RotaService.php' => $pending,
    'app/Modules/Hr/Services/Hrm/LeaveService.php' => $pending,
    'app/Modules/Hotel/Http/Controllers/User/HotelBookingController.php' => $pending,
    'app/Modules/Hotel/Http/Controllers/HotelGuestController.php' => $pending,
    'app/Modules/Hotel/Services/Hotel/HotelFolioService.php' => $pending,
    'app/Modules/Hotel/Services/Hotel/HousekeepingService.php' => $pending,
    'app/Modules/Agents/Tools/StorefrontTools.php' => $pending,
    'app/Modules/Ordering/Models/MealPlan.php' => $pending,
    'app/Modules/Ordering/Http/Controllers/Api/V1/MealPlanController.php' => $pending,
    'app/Modules/Ordering/Services/MealPlanService.php' => $pending,
    'app/Services/Team/ChecklistService.php' => $pending,
];

it('keeps UTC-day idioms out of store-facing code (shrink-only allowlist)', function () use ($allowlist) {
    $root = dirname(__DIR__, 2);
    $pattern = '/whereDate\(\s*\'[a-z_.]*_at\'|Carbon::today\(\)|(?<![>\w])now\(\)->(?:startOfDay|toDateString)\(|(?<![>:\w])today\(\)/';
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Seeders/')) {
            continue;
        }
        if (preg_match($pattern, (string) file_get_contents($file->getPathname()))) {
            $found[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    $new = array_values(array_diff($found, array_keys($allowlist)));
    $stale = array_values(array_diff(array_keys($allowlist), $found));
    expect($new)->toBe([], 'new UTC-day idiom in store-facing code; use App\Support\StoreClock or justify in the allowlist');
    expect($stale)->toBe([], 'allowlist entries that no longer match: remove them (shrink-only)');
});
