<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
/**
 * T10-3 — coverage floor gate.
 *
 * Parses a Clover report and enforces a MINIMUM line-coverage percentage for
 * specific source directories (the money code: app/Services and
 * app/Http/Controllers/User/PaymentMethods). Fails (exit 1) if any tracked dir
 * is below its floor. Floors live in .github/coverage-floor.json so they can be
 * ratcheted UP over time without touching CI.
 *
 * Usage: php .github/scripts/coverage-floor.php core/coverage.xml
 */

$cloverPath = $argv[1] ?? 'core/coverage.xml';
$floorsPath = dirname(__DIR__) . '/coverage-floor.json';

if (!is_file($cloverPath)) {
    fwrite(STDERR, "coverage-floor: clover report not found at {$cloverPath}\n");
    exit(2);
}
$floors = is_file($floorsPath) ? json_decode((string) file_get_contents($floorsPath), true) : [];
if (!is_array($floors) || $floors === []) {
    fwrite(STDERR, "coverage-floor: no floors configured in {$floorsPath}\n");
    exit(2);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "coverage-floor: could not parse {$cloverPath}\n");
    exit(2);
}

// Accumulate covered/total statements per tracked directory.
$totals = [];
foreach ($floors as $dir => $min) {
    $totals[$dir] = ['covered' => 0, 'total' => 0];
}

foreach ($xml->xpath('//file') as $file) {
    $name = (string) $file['name'];
    $m = $file->metrics;
    if (!$m) {
        continue;
    }
    $stmts = (int) $m['statements'];
    $covered = (int) $m['coveredstatements'];
    foreach ($floors as $dir => $min) {
        if (strpos($name, $dir) !== false) {
            $totals[$dir]['covered'] += $covered;
            $totals[$dir]['total'] += $stmts;
        }
    }
}

$failed = false;
foreach ($floors as $dir => $min) {
    $t = $totals[$dir];
    $pct = $t['total'] > 0 ? round($t['covered'] / $t['total'] * 100, 2) : 0.0;
    $status = $pct + 1e-9 >= $min ? 'OK' : 'LOW';
    printf("%-55s %6.2f%%  (floor %s%%)  %s\n", $dir, $pct, $min, $status);
    if ($pct + 1e-9 < $min) {
        $failed = true;
        fwrite(STDERR, "::error::coverage of {$dir} is {$pct}% < floor {$min}%\n");
    }
}

exit($failed ? 1 : 0);
