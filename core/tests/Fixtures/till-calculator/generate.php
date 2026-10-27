<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Writes tests/Fixtures/till-calculator/vectors.json: the golden vectors both till calculators (PHP TillCalculator and
 * assets/pos/till-calculator.js) must reproduce byte for byte. Plain PHP, seeded, deterministic:
 *
 *   php tests/Fixtures/till-calculator/generate.php            (rewrite)
 *   php tests/Fixtures/till-calculator/generate.php --check    (exit 1 when the committed file differs)
 *
 * Hand-picked edge cases first (their expected values are also asserted by hand in TillCalculatorTest), then 320
 * random sales across zero-, two- and three-decimal currencies. A change to the pricing rule must change this file on
 * purpose, in the same commit as both implementations.
 */

require __DIR__.'/../../../app/Modules/Pos/Pricing/TillCalculator.php';

use Juwri\Modules\Pos\Pricing\TillCalculator;

mt_srand(20261008, MT_RAND_MT19937);
$r = fn (int $a, int $b): int => mt_rand($a, $b);

$edge = [
    'empty sale' => ['lines' => []],
    'tax rounds half up at .5' => ['lines' => [['unit_minor' => 3750, 'qty' => 1]], 'tax' => ['mode' => 'percentage', 'rate' => '15']],
    'tax rounds down below .5' => ['lines' => [['unit_minor' => 3, 'qty' => 1]], 'tax' => ['mode' => 'percentage', 'rate' => '15']],
    'tax on the subtotal after discount' => ['lines' => [['unit_minor' => 1000, 'qty' => 2]], 'tax' => ['mode' => 'percentage', 'rate' => '15'], 'discount' => ['mode' => 'amount', 'amount_minor' => 500]],
    'legacy gross tax base' => ['lines' => [['unit_minor' => 1000, 'qty' => 2]], 'tax' => ['mode' => 'percentage', 'rate' => '15'], 'discount' => ['mode' => 'amount', 'amount_minor' => 500], 'tax_base' => 'gross'],
    '100 percent discount' => ['lines' => [['unit_minor' => 999, 'qty' => 3]], 'tax' => ['mode' => 'percentage', 'rate' => '15'], 'discount' => ['mode' => 'percent', 'rate' => '100']],
    'discount above the subtotal is capped' => ['lines' => [['unit_minor' => 500, 'qty' => 1]], 'discount' => ['mode' => 'amount', 'amount_minor' => 9000], 'tip_minor' => 100],
    'fixed tax' => ['lines' => [['unit_minor' => 1250, 'qty' => 2]], 'tax' => ['mode' => 'fixed', 'amount_minor' => 300]],
    'fixed tax not on an empty sale' => ['lines' => [], 'tax' => ['mode' => 'fixed', 'amount_minor' => 300]],
    'four-decimal tax rate' => ['lines' => [['unit_minor' => 123457, 'qty' => 7]], 'tax' => ['mode' => 'percentage', 'rate' => '8.8750']],
    'extras and options times quantity' => ['lines' => [['unit_minor' => 1500, 'qty' => 4, 'extras_minor' => [250, 0, 75], 'options_minor' => [100]]], 'tax' => ['mode' => 'percentage', 'rate' => '5']],
    'zero-price item with priced extras' => ['lines' => [['unit_minor' => 0, 'qty' => 2, 'extras_minor' => [350]]]],
    'quantity 9999' => ['lines' => [['unit_minor' => 1, 'qty' => 9999]], 'tax' => ['mode' => 'percentage', 'rate' => '15']],
    'three-decimal currency (KWD) amounts' => ['lines' => [['unit_minor' => 1375, 'qty' => 3], ['unit_minor' => 250, 'qty' => 1]], 'tax' => ['mode' => 'percentage', 'rate' => '5'], 'discount' => ['mode' => 'percent', 'rate' => '12.5']],
    'zero-decimal currency (JPY) amounts' => ['lines' => [['unit_minor' => 980, 'qty' => 3]], 'tax' => ['mode' => 'percentage', 'rate' => '10'], 'discount' => ['mode' => 'percent', 'rate' => '3.3333']],
    'service charge and tip' => ['lines' => [['unit_minor' => 4500, 'qty' => 1]], 'tax' => ['mode' => 'percentage', 'rate' => '15'], 'service_minor' => 450, 'tip_minor' => 1000],
    'rate 0' => ['lines' => [['unit_minor' => 4500, 'qty' => 1]], 'tax' => ['mode' => 'percentage', 'rate' => '0']],
    'largest allowed line' => ['lines' => [['unit_minor' => 10000000, 'qty' => 9999]], 'tax' => ['mode' => 'percentage', 'rate' => '100']],
    // refused inputs: both implementations must refuse them
    'refuses a negative price' => ['lines' => [['unit_minor' => -1, 'qty' => 1]]],
    'refuses quantity 0' => ['lines' => [['unit_minor' => 100, 'qty' => 0]]],
    'refuses a string amount' => ['lines' => [['unit_minor' => '100', 'qty' => 1]]],
    'refuses five tax decimals' => ['lines' => [['unit_minor' => 100, 'qty' => 1]], 'tax' => ['mode' => 'percentage', 'rate' => '15.00001']],
    'refuses a tax rate above 100' => ['lines' => [['unit_minor' => 100, 'qty' => 1]], 'tax' => ['mode' => 'percentage', 'rate' => '100.5']],
    'refuses an unknown tax mode' => ['lines' => [['unit_minor' => 100, 'qty' => 1]], 'tax' => ['mode' => 'vat']],
    'refuses a line above the maximum' => ['lines' => [['unit_minor' => 100000000000, 'qty' => 2]]],
];

$vectors = [];
foreach ($edge as $name => $input) {
    $vectors[] = ['name' => $name, 'input' => $input];
}
$rates = ['0', '5', '10', '15', '12.5', '7.75', '8.875', '0.0825', '3.3333', '100'];
for ($n = 1; $n <= 320; $n++) {
    $scale = [1, 100, 1000][$r(0, 2)]; // JPY-, SAR- and KWD-like magnitudes
    $lines = [];
    for ($l = 0, $count = $r(1, 8); $l < $count; $l++) {
        $line = ['unit_minor' => $r(0, 500) * $scale + $r(0, $scale - 1), 'qty' => $r(1, 20) === 20 ? $r(21, 500) : $r(1, 6)];
        if ($r(0, 2) === 0) {
            $line['extras_minor'] = array_map(fn () => $r(0, 50) * $scale, range(1, $r(1, 3)));
        }
        if ($r(0, 3) === 0) {
            $line['options_minor'] = array_map(fn () => $r(0, 30) * $scale, range(1, $r(1, 2)));
        }
        $lines[] = $line;
    }
    $input = ['lines' => $lines];
    $input['tax'] = match ($r(0, 5)) {
        0 => ['mode' => 'none'],
        1 => ['mode' => 'fixed', 'amount_minor' => $r(0, 20) * $scale],
        default => ['mode' => 'percentage', 'rate' => $rates[$r(0, count($rates) - 1)]],
    };
    $input['discount'] = match ($r(0, 3)) {
        0 => ['mode' => 'amount', 'amount_minor' => $r(0, 300) * $scale],
        1 => ['mode' => 'percent', 'rate' => $rates[$r(0, count($rates) - 1)]],
        default => ['mode' => 'none'],
    };
    if ($r(0, 4) === 0) {
        $input['service_minor'] = $r(0, 50) * $scale;
    }
    if ($r(0, 4) === 0) {
        $input['tip_minor'] = $r(0, 100) * $scale;
    }
    $vectors[] = ['name' => "random $n", 'input' => $input];
}

foreach ($vectors as &$v) {
    try {
        $v['expected'] = TillCalculator::price($v['input']);
    } catch (InvalidArgumentException) {
        $v['expected'] = ['error' => true];
    }
}
unset($v);

// One vector per line: small, and a changed rule shows as a readable diff.
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
$json = "{\"schema\": 1, \"rule\": \"OFFLINE-POS-SPEC 4.1\", \"vectors\": [\n"
    .implode(",\n", array_map(fn ($v) => json_encode($v, $flags), $vectors))."\n]}\n";
$file = __DIR__.'/vectors.json';
if (in_array('--check', $argv, true)) {
    $same = is_file($file) && file_get_contents($file) === $json;
    fwrite(STDOUT, $same ? "vectors.json is current\n" : "vectors.json is stale: run generate.php\n");
    exit($same ? 0 : 1);
}
file_put_contents($file, $json);
fwrite(STDOUT, count($vectors)." vectors written\n");
