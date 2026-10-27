<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use PHPUnit\Framework\TestCase;

/**
 * MC-01 — the gateway minor-unit contract. Pins the live-mischarge fix: three
 * decimal currencies (OMR/KWD/BHD/…) must go ×1000 with the trailing minor
 * digit forced to 0, zero-decimal currencies ×1, everything else ×100.
 */
class GatewayMinorUnitsTest extends TestCase
{
    public function test_decimals_by_currency_class(): void
    {
        $this->assertSame(0, GatewayMinorUnits::decimals('JPY'));
        $this->assertSame(0, GatewayMinorUnits::decimals('KRW'));
        $this->assertSame(3, GatewayMinorUnits::decimals('OMR'));
        $this->assertSame(3, GatewayMinorUnits::decimals('KWD'));
        $this->assertSame(3, GatewayMinorUnits::decimals('IQD'));
        $this->assertSame(2, GatewayMinorUnits::decimals('USD'));
        $this->assertSame(2, GatewayMinorUnits::decimals('EUR'));
    }

    public function test_decimals_is_case_and_whitespace_insensitive(): void
    {
        $this->assertSame(3, GatewayMinorUnits::decimals(' omr '));
        $this->assertSame(2, GatewayMinorUnits::decimals(null));
        $this->assertSame(2, GatewayMinorUnits::decimals(''));
    }

    public function test_two_decimal_currency_times_100(): void
    {
        $this->assertSame(1000, GatewayMinorUnits::toMinor(10, 'USD'));
        $this->assertSame(1050, GatewayMinorUnits::toMinor(10.50, 'USD'));
        $this->assertSame(999, GatewayMinorUnits::toMinor(9.99, 'EUR'));
    }

    public function test_zero_decimal_currency_times_1(): void
    {
        $this->assertSame(1000, GatewayMinorUnits::toMinor(1000, 'JPY'));
        $this->assertSame(1500, GatewayMinorUnits::toMinor(1500.4, 'KRW'));
    }

    public function test_three_decimal_currency_times_1000_last_digit_zeroed(): void
    {
        // The canonical live-mischarge case: OMR 1.234 → 1234 → 1230.
        $this->assertSame(1230, GatewayMinorUnits::toMinor(1.234, 'OMR'));
        $this->assertSame(1240, GatewayMinorUnits::toMinor(1.238, 'OMR'));
        $this->assertSame(10000, GatewayMinorUnits::toMinor(10, 'KWD'));
        $this->assertSame(2750, GatewayMinorUnits::toMinor(2.75, 'BHD'));
        $this->assertSame(500, GatewayMinorUnits::toMinor(0.5, 'JOD'));
    }

    public function test_old_bug_would_have_charged_one_tenth(): void
    {
        // Regression guard: the old code did number_format(1.234, 2) * 100 = 123,
        // i.e. 0.123 OMR. The contract now yields 1230 (= 1.230 OMR).
        $this->assertNotSame(123, GatewayMinorUnits::toMinor(1.234, 'OMR'));
        $this->assertSame(1230, GatewayMinorUnits::toMinor(1.234, 'OMR'));
    }

    public function test_string_amount_accepted(): void
    {
        $this->assertSame(1230, GatewayMinorUnits::toMinor('1.234', 'OMR'));
        $this->assertSame(1050, GatewayMinorUnits::toMinor('10.50', 'USD'));
    }

    /* ---- major-unit formatters (redirect/hosted-checkout drivers: Tap, Paytabs,
            Telr, Tabby, Tamara) — the Gulf fils-truncation fix ---- */

    public function test_to_major_float_keeps_three_decimals_for_fils_currencies(): void
    {
        // The truncation bug: round($amount, 2) dropped the 3rd digit — KWD 1.234
        // charged as 1.23, under-billing 4 fils per order. Now precision is by currency.
        $this->assertSame(1.234, GatewayMinorUnits::toMajorFloat(1.234, 'KWD'));
        $this->assertSame(1.234, GatewayMinorUnits::toMajorFloat('1.2344', 'OMR'));
        $this->assertSame(10.5, GatewayMinorUnits::toMajorFloat(10.5, 'USD'));  // 2-dp unaffected
        $this->assertSame(1500.0, GatewayMinorUnits::toMajorFloat(1500.4, 'JPY')); // 0-dp rounds
    }

    public function test_to_major_string_formats_with_currency_precision(): void
    {
        $this->assertSame('1.234', GatewayMinorUnits::toMajorString(1.234, 'BHD'));
        $this->assertSame('1.230', GatewayMinorUnits::toMajorString(1.23, 'OMR'));   // 3-dp padded
        $this->assertSame('10.50', GatewayMinorUnits::toMajorString(10.5, 'USD'));   // 2-dp padded
        $this->assertSame('1000', GatewayMinorUnits::toMajorString(1000, 'JPY'));    // 0-dp, no point
    }

    public function test_to_major_string_regression_no_2dp_truncation(): void
    {
        // Old: number_format(1.234, 2) = "1.23". New must NOT truncate for fils.
        $this->assertNotSame('1.23', GatewayMinorUnits::toMajorString(1.234, 'KWD'));
        $this->assertSame('1.234', GatewayMinorUnits::toMajorString(1.234, 'KWD'));
    }
}
