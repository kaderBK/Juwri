<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Juwri\Core\Money\Money;
use Tests\TestCase;

/**
 * PayPal currency precision: per-gateway decimal conventions (GatewayMinorUnits::GATEWAY_ZERO_DECIMAL). PayPal takes
 * HUF/TWD/JPY whole, Midtrans IDR whole; an amount with a fraction the gateway cannot carry gives null (refused,
 * never rounded); every other gateway/currency is exactly majorStringFromMoney().
 */
class GatewayDecimalConventionTest extends TestCase
{
    public function test_gateway_decimals(): void
    {
        $this->assertSame(0, GatewayMinorUnits::gatewayDecimals('paypal', 'HUF'));
        $this->assertSame(0, GatewayMinorUnits::gatewayDecimals('PayPal', ' twd '));
        $this->assertSame(0, GatewayMinorUnits::gatewayDecimals('paypal', 'JPY'));
        $this->assertSame(2, GatewayMinorUnits::gatewayDecimals('paypal', 'USD'));
        $this->assertSame(3, GatewayMinorUnits::gatewayDecimals('paypal', 'KWD'));
        $this->assertSame(0, GatewayMinorUnits::gatewayDecimals('midtrans', 'IDR'));
        $this->assertSame(2, GatewayMinorUnits::gatewayDecimals('stripe', 'HUF'));
        $this->assertSame(2, GatewayMinorUnits::gatewayDecimals('paypal', 'IDR'));
    }

    public function test_whole_amounts_drop_the_decimals(): void
    {
        $this->assertSame('1500', GatewayMinorUnits::gatewayMajorString('paypal', Money::ofMinor(150000, 'HUF')));
        $this->assertSame('300', GatewayMinorUnits::gatewayMajorString('paypal', Money::ofMinor(30000, 'TWD')));
        $this->assertSame('1200', GatewayMinorUnits::gatewayMajorString('paypal', Money::ofMinor(1200, 'JPY')));
        $this->assertSame('15000', GatewayMinorUnits::gatewayMajorString('midtrans', Money::ofMinor(1500000, 'IDR')));
    }

    public function test_a_fraction_the_gateway_cannot_carry_is_null(): void
    {
        $this->assertNull(GatewayMinorUnits::gatewayMajorString('paypal', Money::ofMinor(150050, 'HUF')));
        $this->assertNull(GatewayMinorUnits::gatewayMajorString('paypal', Money::ofMinor(30001, 'TWD')));
        $this->assertNull(GatewayMinorUnits::gatewayMajorString('midtrans', Money::ofMinor(1500050, 'IDR')));
    }

    public function test_other_gateways_and_currencies_are_the_plain_major_string(): void
    {
        foreach ([[150050, 'HUF', 'stripe'], [1250, 'USD', 'paypal'], [12345, 'KWD', 'paypal'], [13833, 'IQD', 'paypal'], [150050, 'IDR', 'paypal']] as [$minor, $code, $gateway]) {
            $money = Money::ofMinor($minor, $code);
            $this->assertSame(GatewayMinorUnits::majorStringFromMoney($money), GatewayMinorUnits::gatewayMajorString($gateway, $money), "{$gateway} {$code}");
        }
        $this->assertSame('0', GatewayMinorUnits::gatewayMajorString('paypal', null, 'HUF'));
    }
}
