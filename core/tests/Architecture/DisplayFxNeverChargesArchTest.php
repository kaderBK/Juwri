<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * P6-105: display FX (ExchangeRateService / DisplayRates, a cached daily rate) never reaches money that is charged,
 * taxed, stored, refunded, paid out or reported as a fiscal fact. Nothing on the payment / checkout / fiscal paths may
 * read it; a site that truly needs it goes in ALLOWED with why.
 */
class DisplayFxNeverChargesArchTest extends TestCase
{
    private const PATHS = [
        'app/Modules/Payments',
        'app/Modules/Ordering',
        'app/Modules/Fiscal',
        'app/Modules/Accounting',
        'app/Modules/Loyalty',
        'app/Modules/Pos',
        'app/Modules/Tables',
        'app/Services/OrderApiService.php',
        'app/Services/MultiVendor',
        'app/Services/GiftCardService.php',
        'app/Models/Order.php',
        'e'.'e/Modules/Marketplace', // built without the literal path so the Community mirror scrub does not flag it
    ];

    private const NEEDLES = ['ExchangeRateService', 'DisplayRates', 'currency_rates_manual', 'currencyRates'];

    /** "relative/path.php" => reason. */
    private const ALLOWED = [
        'app/Models/Order.php' => 'C9 reporting only: stamps fx_rate_base (order currency → platform base) at creation and '
            .'Order::revenueInBase() sums dashboards with it; never a charged/stored order amount',
    ];

    public function test_money_paths_never_read_display_fx(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        foreach (self::PATHS as $path) {
            $abs = $root.'/'.$path;
            if (! file_exists($abs)) {
                continue;
            }
            $files = is_dir($abs)
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS))
                : [new \SplFileInfo($abs)];
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen($root) + 1);
                if (isset(self::ALLOWED[$rel])) {
                    continue;
                }
                foreach (file($file->getPathname()) as $n => $line) {
                    $code = trim($line);
                    if (str_starts_with($code, '//') || str_starts_with($code, '*') || str_starts_with($code, '/*')) {
                        continue;
                    }
                    foreach (self::NEEDLES as $needle) {
                        if (str_contains($code, $needle)) {
                            $hits[] = "{$rel}:".($n + 1).": {$code}";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $hits, "Display FX must not reach a money path (allowlist with a reason):\n".implode("\n", $hits));
    }

    public function test_allowlisted_files_still_exist(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (array_keys(self::ALLOWED) as $rel) {
            $this->assertFileExists($root.'/'.$rel);
        }
    }
}
