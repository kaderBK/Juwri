<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * P6-81: gateway code formats amounts through GatewayMinorUnits (the currency's — or provider's — decimals), never a
 * hard-coded number_format(…, 2), round(…, 2), ×100 or ÷100. A site truly fixed by the provider goes in ALLOWED with why.
 */
class GatewayAmountDecimalsArchTest extends TestCase
{
    private const DIRS = [
        'app/Modules/Payments/Services/Gateways',
        'app/Modules/Payments/Services/Terminal',
        'app/Modules/Payments/Http/Controllers/User/PaymentMethods',
    ];

    /** "relative/path.php" => [substring of the line => reason]. */
    private const ALLOWED = [
        'app/Modules/Payments/Services/Gateways/FlutterwaveAdapter.php' => [
            'round((float) $transaction->amount, 2)' => 'legacyAmount(): matches payments created before the decimals fix, which were sent at the old 2 decimals',
        ],
    ];

    private const PATTERNS = [
        '/number_format\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*?,\s*2\s*[,)]/' => 'number_format(…, 2)',
        '/\bround\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*?,\s*2\s*\)/' => 'round(…, 2)',
        '/\*\s*100\b(?!\s*\.)|\b100\s*\*/' => '× 100',
        '/\/\s*100\b(?!\s*\.)/' => '÷ 100',
    ];

    public function test_gateway_dirs_do_not_hard_code_two_decimals(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        foreach (self::DIRS as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen($root) + 1);
                foreach (file($file->getPathname()) as $n => $line) {
                    $code = trim($line);
                    if (str_starts_with($code, '//') || str_starts_with($code, '*') || str_starts_with($code, '/*')) {
                        continue;
                    }
                    $code = preg_replace('#\s//.*$#', '', $code);
                    foreach (self::PATTERNS as $re => $what) {
                        if (preg_match($re, $code) !== 1 || ! preg_match('/amount|price|total|minor|paid|refund|money|value|\$cost|fee/i', $code)) {
                            continue;
                        }
                        foreach (self::ALLOWED[$rel] ?? [] as $needle => $reason) {
                            if (str_contains($code, $needle)) {
                                continue 2;
                            }
                        }
                        $hits[] = "{$rel}:".($n + 1)." {$what}: {$code}";
                    }
                }
            }
        }

        $this->assertSame([], $hits, "Route gateway amounts through GatewayMinorUnits (or allowlist with a reason):\n".implode("\n", $hits));
    }

    public function test_the_pattern_catches_the_old_shapes(): void
    {
        $bad = ["return number_format((float) \$amount, 2, '.', '');", '$m = (int) round(((float) $tx->amount) * 100);', '$x = round($amount, 2);', '$major = $minor / 100;'];
        foreach ($bad as $code) {
            $matched = false;
            foreach (self::PATTERNS as $re => $_) {
                $matched = $matched || preg_match($re, $code) === 1;
            }
            $this->assertTrue($matched, $code);
        }
    }
}
