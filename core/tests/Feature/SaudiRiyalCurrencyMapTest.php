<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Controllers\Admin\UserController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Saudi Riyal Symbol (U+20C1) rollout — S10: the one authoritative
 * currency_symbol_map(), the thin wrappers that now read from it, and the
 * settings autofill that emits it to the browser.
 */
class SaudiRiyalCurrencyMapTest extends TestCase
{
    private const RIYAL = "\u{20C1}";     // ⃁  SAUDI RIYAL SIGN

    private const RIAL = "\u{FDFC}";      // ﷼  generic rial ligature

    public function test_the_canonical_map_and_lookup(): void
    {
        $map = currency_symbol_map();

        // Locked decisions §1.
        $this->assertSame(self::RIYAL, $map['SAR']);
        $this->assertSame(self::RIAL, $map['IRR']);
        $this->assertSame(self::RIAL, $map['YER']);
        // AED is the new UAE Dirham sign U+20C3 (font gate cleared 2026-08-12);
        // OMR keeps its look-alike sign until a redistributable U+20C4 font exists.
        $this->assertSame('ر.ع.', $map['OMR']);
        $this->assertSame("\u{20C3}", $map['AED']);
        $this->assertSame('$', $map['USD']);

        // The lookup falls back to the ISO code itself for an unknown code.
        $this->assertSame(self::RIYAL, currency_symbol_for('SAR'));
        $this->assertSame(self::RIAL, currency_symbol_for('irr'));   // case-insensitive
        $this->assertSame('$', currency_symbol_for('USD'));
        $this->assertSame('XXX', currency_symbol_for('XXX'));
    }

    /** The admin wrapper must be exactly the central lookup now. */
    public function test_currency_symbol_for_code_is_a_thin_wrapper(): void
    {
        $method = new ReflectionMethod(UserController::class, 'currencySymbolForCode');
        $method->setAccessible(true);
        $controller = app(UserController::class);

        foreach (['SAR', 'USD', 'AED', 'IRR', 'YER', 'ZZZ'] as $code) {
            $this->assertSame(
                currency_symbol_for($code),
                $method->invoke($controller, $code),
                "currencySymbolForCode({$code}) must match the central map"
            );
        }
    }

    /**
     * The autofill partial must emit the SAME map the server holds — decode it out
     * of the emitted JS and compare, robust to whatever escaping @json applies
     * (it escapes slashes and non-ASCII).
     */
    public function test_the_autofill_partial_emits_the_server_map(): void
    {
        $html = view('partials.currency-autofill')->render();

        $this->assertMatchesRegularExpression('/var MAP = (\{.*?\});/s', $html);
        preg_match('/var MAP = (\{.*?\});/s', $html, $m);
        $decoded = json_decode($m[1], true);

        $this->assertSame(
            currency_symbol_map(),
            $decoded,
            'the JS map must decode to exactly the server currency_symbol_map()'
        );

        // The guard that stops a custom sign being clobbered must be present.
        $this->assertStringContainsString("sign.value === ''", $html);
        $this->assertStringContainsString('hasOwnProperty', $html);
    }
}
