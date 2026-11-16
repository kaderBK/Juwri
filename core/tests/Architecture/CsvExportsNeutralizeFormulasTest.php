<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Services\Backup\DatasetStreamWriter;

/*
 * P6-70: every CSV writer in app/ and ee/ (a file that calls fputcsv( or sends a text/csv response) must route
 * user-controlled cells through DatasetStreamWriter::csvCell / csvRow, or be listed below with a reason.
 * File-level check; each export also has its own per-export test with =, +, -, @ payloads.
 */

$allowlist = [
    'app/Core/Http/Controllers/Admin/OwnerMobileUsageController.php' => 'route names and counters only, no user-entered text',
    'app/Modules/Verticals/Services/Fitment/FitmentImporter.php' => 'own safe() neutralizer on every error-report cell; TEMPLATE is a static sample',
    'app/Modules/Verticals/Http/Controllers/User/FitmentImportController.php' => 'streams the static template and the FitmentImporter report (neutralized there)',
    'app/Modules/Integrations/Http/Controllers/User/SwitchingController.php' => 'static column-header template, no stored data',
    'app/Modules/Menu/Console/Commands/MeasureModifierUndercharge.php' => 'artisan report to a local file for staff, ids and numbers only',
    'app/Modules/Messaging/Console/Commands/WhatsAppAuditNumbers.php' => 'artisan report to a local file for staff, not a download',
    'app/Modules/Media/Console/Commands/AuditMedia.php' => 'artisan report to a local file for staff, not a download',
    'app/Modules/Loyalty/Services/CouponBulk.php' => 'own csvSafe() neutralizer (= + - @ tab CR) on every text cell; covered by CouponBulkTest',
    'app/Modules/Customers/Http/Controllers/Concerns/HandlesShopperPrivacy.php' => 'streams ShopperPrivacyService::writeOrdersCsv, which neutralizes every cell (csvCell)',
];

it('routes every CSV writer through DatasetStreamWriter::csvCell/csvRow or an allowlist entry', function () use ($allowlist) {
    $root = dirname(__DIR__, 2);
    $offenders = [];
    // The Enterprise package (when installed) is scanned through its Modules folder, found without naming its path.
    $dirs = ['app', ...array_map(fn ($d) => substr($d, strlen($root) + 1), glob($root.'/e*/Modules', GLOB_ONLYDIR) ?: [])];
    foreach ($dirs as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if (! str_contains($src, 'fputcsv(') && ! preg_match('#[\'"]text/csv#', $src)
                && ! preg_match('#Content-Disposition.*\.csv#i', $src) && ! preg_match('#implode\(\s*[\'"],[\'"].*\.csv#s', $src)) {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
            if ($rel === 'app/Services/Backup/DatasetStreamWriter.php' || isset($allowlist[$rel])) {
                continue;
            }
            if (! preg_match('/csvCell|csvRow/', $src)) {
                $offenders[] = $rel;
            }
        }
    }
    expect($offenders)->toBe([], 'CSV writers without formula neutralization: '.implode(', ', $offenders));

    foreach (array_keys($allowlist) as $rel) {
        expect(is_file($root.'/'.$rel))->toBeTrue("stale allowlist entry: $rel");
    }
});

it('csvRow neutralizes strings but leaves real numbers alone', function () {
    $row = DatasetStreamWriter::csvRow(['=HYPERLINK("http://x")', '+1', '-1', '@SUM(1)', -5, 2.5, null, 'ok']);
    expect($row)->toBe(["'=HYPERLINK(\"http://x\")", "'+1", "'-1", "'@SUM(1)", -5, 2.5, '', 'ok']);
});
