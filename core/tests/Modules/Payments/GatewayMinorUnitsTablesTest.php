<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use PHPUnit\Framework\TestCase;

/**
 * P1-18 part 2: pins every entry of GatewayMinorUnits' currency tables (ZERO_DECIMAL, THREE_DECIMAL,
 * GATEWAY_ZERO_DECIMAL, GATEWAY_MAX_DECIMALS, GATEWAY_STEP_DECIMALS, GATEWAY_UNSUPPORTED) through the public methods, with
 * the expected lists written out independently here. The mutation run cannot see constant declarations as covered lines,
 * so without this a dropped or changed entry would go unnoticed. Provider docs for each entry are in the class docblocks.
 */
class GatewayMinorUnitsTablesTest extends TestCase
{
    public function test_iso_decimals_table(): void
    {
        foreach (['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'] as $code) {
            $this->assertSame(0, GatewayMinorUnits::decimals($code), $code);
            $this->assertSame(0, GatewayMinorUnits::decimals(strtolower(' '.$code.' ')), "$code trimmed and lower-cased");
        }
        foreach (['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'] as $code) {
            $this->assertSame(3, GatewayMinorUnits::decimals($code), $code);
        }
        foreach (['USD', 'EUR', 'GBP', 'SAR', 'AED', 'EGP', 'ISK', 'HUF', 'TWD', 'IDR', null, ''] as $code) {
            $this->assertSame(2, GatewayMinorUnits::decimals($code), (string) $code);
        }
    }

    public function test_gateway_own_decimals_tables(): void
    {
        // PayPal: HUF, TWD, JPY whole units only; everything else at the ISO decimals.
        foreach (['HUF', 'TWD', 'JPY'] as $code) {
            $this->assertSame(0, GatewayMinorUnits::gatewayDecimals('paypal', $code), "paypal $code");
        }
        $this->assertSame(2, GatewayMinorUnits::gatewayDecimals('paypal', 'USD'));
        $this->assertSame(3, GatewayMinorUnits::gatewayDecimals('paypal', 'KWD'));
        $this->assertSame(0, GatewayMinorUnits::gatewayDecimals(' PayPal ', ' huf '), 'gateway and code are normalised');
        // Midtrans IDR whole rupiah, Mollie ISK 0.
        $this->assertSame(0, GatewayMinorUnits::gatewayDecimals('midtrans', 'IDR'));
        $this->assertSame(2, GatewayMinorUnits::gatewayDecimals('midtrans', 'USD'));
        $this->assertSame(2, GatewayMinorUnits::gatewayDecimals('stripe', 'IDR'));
        $this->assertSame(0, GatewayMinorUnits::gatewayDecimals('mollie', 'ISK'));
        $this->assertSame(2, GatewayMinorUnits::gatewayDecimals('stripe', 'ISK'));
        $this->assertSame(2, GatewayMinorUnits::gatewayDecimals('mollie', 'HUF'));
    }

    public function test_gateways_capped_at_two_decimals(): void
    {
        foreach (['geidea', 'paylink', 'fawry', 'paymob'] as $gateway) {
            $this->assertSame(2, GatewayMinorUnits::gatewayDecimals($gateway, 'KWD'), "$gateway KWD");
            $this->assertSame(2, GatewayMinorUnits::gatewayDecimals($gateway, 'BHD'), "$gateway BHD");
            $this->assertSame(2, GatewayMinorUnits::gatewayDecimals($gateway, 'USD'), "$gateway USD");
            $this->assertSame(0, GatewayMinorUnits::gatewayDecimals($gateway, 'JPY'), "$gateway JPY");
        }
        foreach (['noon', 'fatora', 'stripe', 'tap'] as $gateway) {
            $this->assertSame(3, GatewayMinorUnits::gatewayDecimals($gateway, 'KWD'), "$gateway KWD");
        }
    }

    public function test_charging_step_tables(): void
    {
        foreach (['BHD', 'JOD', 'KWD', 'OMR', 'TND'] as $code) {
            $this->assertSame(2, GatewayMinorUnits::gatewayPrecision('stripe', $code), "stripe $code");
        }
        $this->assertSame(3, GatewayMinorUnits::gatewayPrecision('stripe', 'IQD'), 'stripe IQD has no step table entry');
        $this->assertSame(3, GatewayMinorUnits::gatewayPrecision('stripe', 'LYD'));
        $this->assertSame(0, GatewayMinorUnits::gatewayPrecision('stripe', 'ISK'));
        $this->assertSame(2, GatewayMinorUnits::gatewayPrecision('stripe', 'USD'));
        foreach (['razorpay', 'lemon_squeezy'] as $gateway) {
            foreach (['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'] as $code) {
                $this->assertSame(2, GatewayMinorUnits::gatewayPrecision($gateway, $code), "$gateway $code");
            }
            $this->assertSame(2, GatewayMinorUnits::gatewayPrecision($gateway, 'USD'));
        }
        $this->assertSame(0, GatewayMinorUnits::gatewayPrecision('mollie', 'HUF'));
        $this->assertSame(0, GatewayMinorUnits::gatewayPrecision('mollie', 'TWD'));
        $this->assertSame(2, GatewayMinorUnits::gatewayPrecision('mollie', 'USD'));
        $this->assertSame(3, GatewayMinorUnits::gatewayPrecision('mollie', 'KWD'));
    }

    public function test_unsupported_table(): void
    {
        $this->assertTrue(GatewayMinorUnits::gatewayUnsupported('stripe', 'UGX'));
        $this->assertTrue(GatewayMinorUnits::gatewayUnsupported(' Stripe ', ' ugx '));
        $this->assertFalse(GatewayMinorUnits::gatewayUnsupported('stripe', 'JPY'));
        $this->assertFalse(GatewayMinorUnits::gatewayUnsupported('stripe', null));
        $this->assertFalse(GatewayMinorUnits::gatewayUnsupported('paypal', 'UGX'));
        $this->assertNull(GatewayMinorUnits::gatewayMajorString('stripe', Money::ofMinor(500, 'UGX'), 'UGX'));
        $this->assertNull(GatewayMinorUnits::gatewayMinor('stripe', Money::ofMinor(500, 'UGX'), 'UGX'));
    }

    public function test_paid_matches_refuses_anything_that_is_not_exactly_the_amount(): void
    {
        $usd = Money::ofMinor(1250, 'USD');
        $this->assertTrue(GatewayMinorUnits::paidMatches('stripe', '12.50', $usd, 'USD'));
        $this->assertTrue(GatewayMinorUnits::paidMatches('stripe', 12.5, $usd, 'USD'));
        $this->assertTrue(GatewayMinorUnits::paidMatches('stripe', ' 12.5 ', $usd, 'USD'));
        $this->assertTrue(GatewayMinorUnits::paidMatches('stripe', 1250, Money::ofMinor(125000, 'USD'), 'USD'), 'USD 1250.00 stored, 1250 paid');
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', 125000, Money::ofMinor(125000, 'USD'), 'USD'), 'minor units are not major units');
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', '12.49', $usd, 'USD'));
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', '12.51', $usd, 'USD'));
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', '12.50', null, 'USD'), 'no expected amount');
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', 'twelve', $usd, 'USD'));
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', '', $usd, 'USD'));
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', null, $usd, 'USD'));
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', [12.5], $usd, 'USD'));
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', NAN, $usd, 'USD'));
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', INF, $usd, 'USD'));
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', '12.50', Money::ofMinor(500, 'UGX'), 'UGX'), 'unsupported gateway currency');
        // Exponent notation is numeric to PHP but made brick/math allocate the whole number: a memory fatal that no catch stops
        // (bug found by the mutation run; paidMatches now takes plain decimals only).
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', '1e50', $usd, 'USD'));
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', '1.25e1', $usd, 'USD'));
        $this->assertTrue(GatewayMinorUnits::paidMatches('stripe', '12.500000000000000000', $usd, 'USD'));
        $this->assertTrue(GatewayMinorUnits::paidMatches('stripe', '+12.5', $usd, 'USD'));
        $this->assertTrue(GatewayMinorUnits::paidMatches('stripe', '012.50', $usd, 'USD'));
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', '1e999999999999', $usd, 'USD'));
        $this->assertFalse(GatewayMinorUnits::paidMatches('stripe', '.5e', $usd, 'USD'));
    }
}
