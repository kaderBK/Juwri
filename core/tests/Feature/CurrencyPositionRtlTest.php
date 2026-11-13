<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locale-aware currency-symbol position: RTL languages (Arabic, Urdu, …) place the
 * symbol AFTER the number by default; the owner can turn that off per store, and
 * LTR always honors the stored currency_pos flag.
 */
class CurrencyPositionRtlTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // These tests switch app locale to 'ar'; reset it so the RTL locale does not
        // leak into later tests (currency_pos_effective() is locale-sensitive).
        app()->setLocale('en');
        parent::tearDown();
    }

    public function test_effective_position_forces_after_in_rtl_but_honors_flag_in_ltr(): void
    {
        config(['settings.currency_pos' => 1, 'settings.currency_rtl_auto' => '1', 'settings.currency_code' => 'SAR']);

        app()->setLocale('en');
        $this->assertSame(1, currency_pos_effective(), 'LTR honors the flag (before)');

        app()->setLocale('ar');
        // RTL "after" = sign AFTER the number = a NUMBER-FIRST string (pos 0): the
        // digits are an LTR run laid rightmost, so the sign renders to their left =
        // visually after. (A sign-first string would render the sign rightmost =
        // before, which is wrong.)
        $this->assertSame(0, currency_pos_effective(), 'RTL auto-places the symbol after (number-first string)');

        // Owner turns the RTL auto rule off → RTL honors the flag; flag here is 0.
        config(['settings.currency_pos' => 0, 'settings.currency_rtl_auto' => '0']);
        $this->assertSame(0, currency_pos_effective(), 'RTL with auto OFF honors the flag');
        config(['settings.currency_pos' => 1]);

        app()->setLocale('en');
        $this->assertSame(1, currency_pos_effective(), 'LTR always honors the flag');
    }

    public function test_price_symbol_format_places_sign_after_the_number_in_arabic(): void
    {
        config([
            'settings.currency_pos' => 1,          // "before" for LTR
            'settings.currency_sign' => '⃁',       // U+20C1
            'settings.currency_code' => 'SAR',
            'settings.currency_rtl_auto' => '1',
        ]);

        // The web helper wraps the sign in a Unicode isolate (cur_isolate) so an
        // Arabic-script sign lands on the same side as a neutral one.
        $iso = cur_isolate('⃁');
        $this->assertNotSame('⃁', $iso, 'the sign must be isolate-wrapped for web display');

        // The riyal glyph has no side-bearing, so a space separates it from the
        // digits (currency_symbol_gap) — on the number-facing side, so it survives
        // the RTL flip as a gap between number and sign.
        app()->setLocale('en');
        $this->assertSame($iso.' 10.00', price_symbol_format(10), 'LTR before: sign leads, spaced');

        app()->setLocale('ar');
        // Arabic "after" renders the sign to the LEFT of the digits, which the bidi
        // algorithm produces from a NUMBER-FIRST string (the digits are an LTR run
        // laid rightmost, so the sign falls to their left = visually after).
        $this->assertSame('10.00 '.$iso, price_symbol_format(10), 'Arabic: number-first string renders the sign visually after the number, spaced');

        // The safe (non-web) helper uses the bare ISO code ("SAR"), no isolate chars
        // (exports clean); it follows the same number-first order in RTL.
        $this->assertSame('10.00SAR', price_symbol_format_safe(10), 'Arabic: number-first, ISO code after the number');
    }
}
