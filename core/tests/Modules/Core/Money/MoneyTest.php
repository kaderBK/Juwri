<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P1-05 (J25-4): money value object on brick/money, currencies.exponent, PHP-version independent rounding.
 */
class MoneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Currencies::flush();
    }

    public function test_currencies_table_carries_iso_exponents(): void
    {
        $exp = DB::table('currencies')->pluck('exponent', 'code')->map(fn ($e) => (int) $e);
        foreach (['JOD', 'KWD', 'BHD', 'OMR', 'IQD', 'LYD', 'TND'] as $code) {
            $this->assertSame(3, $exp[$code], $code);
            $this->assertSame(3, Currencies::exponent($code));
        }
        foreach (['JPY', 'KRW', 'VND', 'CLP', 'XOF'] as $code) {
            $this->assertSame(0, Currencies::exponent($code), $code);
        }
        $this->assertSame(2, Currencies::exponent('SAR'));
        $this->assertSame(2, Currencies::exponent('usd'));
        $this->assertSame(2, Currencies::exponent('ZZZ'), 'unknown code');
        $this->assertSame(2, Currencies::exponent(null));
        // Every country's currency resolves (AAD is not ISO → default 2).
        $this->assertSame(0, DB::table('countries')->whereNotIn('currency', $exp->keys())->where('currency', '!=', 'AAD')->count());
    }

    public function test_storage_exponent_never_less_precise_than_the_gateway_converter(): void
    {
        foreach (GatewayMinorUnits::THREE_DECIMAL as $code) {
            $this->assertSame(3, Currencies::exponent($code), $code);
        }
        // Gateway conventions may be coarser than ISO (Stripe charges MGA in whole units, ISO has 2 decimals):
        // storage keeps ISO, GatewayMinorUnits keeps the gateway's rule.
        foreach (GatewayMinorUnits::ZERO_DECIMAL as $code) {
            $this->assertGreaterThanOrEqual(GatewayMinorUnits::decimals($code), Currencies::exponent($code), $code);
        }
        $this->assertSame(2, Currencies::exponent('MGA'));
    }

    public function test_minor_and_major_round_trip_per_exponent(): void
    {
        $this->assertSame(12345, Money::minorFromMajor('12.345', 'JOD'));
        $this->assertSame(1235, Money::minorFromMajor(12.345, 'USD'), 'half up');
        $this->assertSame(500, Money::minorFromMajor('499.5', 'JPY'));
        $this->assertSame(-1235, Money::minorFromMajor(-12.345, 'USD'), 'half away from zero');
        $this->assertSame(12.345, Money::majorFromMinor(12345, 'KWD'));
        $this->assertSame(123.45, Money::majorFromMinor(12345, 'SAR'));
        $this->assertSame(12345.0, Money::majorFromMinor(12345, 'JPY'));
        $this->assertSame('12.345', Money::ofMinor(12345, 'OMR')->major());
        $this->assertSame(['amount' => '1.50', 'minor' => 150, 'currency' => 'USD'], Money::ofMajor(1.5, 'usd')->jsonSerialize());
        // A float that is not exact in binary is read at 15 significant digits.
        $this->assertSame(62, Money::minorFromMajor(4.10 * 0.15, 'USD'));
        $this->assertSame(1234, Money::minorFromMajor('12.345', 'USD', RoundingMode::Down));
    }

    public function test_arithmetic_tax_and_allocation_never_lose_a_minor_unit(): void
    {
        $total = Money::ofMajor('10.00', 'USD');
        $this->assertSame([334, 333, 333], array_map(fn (Money $m) => $m->minor(), $total->split(3)));
        $this->assertSame([500, 250, 250], array_map(fn (Money $m) => $m->minor(), $total->allocate([2, 1, 1])));
        $this->assertSame(1000, array_sum(array_map(fn (Money $m) => $m->minor(), $total->allocate([1, 1, 1, 1, 1, 1, 1]))));

        $this->assertSame(1500, Money::ofMajor('10.000', 'KWD')->percent(15)->minor());
        $this->assertSame(4, Money::ofMajor('0.035', 'USD')->minor(), '0.035 half up');
        $this->assertSame('11.500', Money::ofMajor(10, 'KWD')->plus('1.5')->major());
        $this->assertSame('8.500', Money::ofMajor(10, 'KWD')->minus(Money::ofMajor('1.5', 'KWD'))->major());
        $this->assertSame('3.333', Money::ofMajor(10, 'JOD')->dividedBy(3)->major());
        $this->assertTrue(Money::zero('USD')->isZero());
        $this->assertTrue(Money::ofMajor(-1, 'USD')->isNegative());
        $this->assertSame(1, Money::ofMajor(2, 'USD')->compareTo(Money::ofMajor(1, 'USD')));
    }

    /** @return array<string, array{float, int, float}> */
    public static function roundCases(): array
    {
        return [
            '0.035 (PHP 8.4 round gives 0.03)' => [0.035, 2, 0.04],
            '4.10 x 15%' => [4.10 * 0.15, 2, 0.62],
            '1.005' => [1.005, 2, 1.01],
            '2.675' => [2.675, 2, 2.68],
            'negative half' => [-0.035, 2, -0.04],
            'fils' => [1.0005, 3, 1.001],
            'integer half' => [0.5, 0, 1.0],
            'below the cut' => [0.0049, 2, 0.0],
            'large' => [123456789.125, 2, 123456789.13],
            'negative places' => [1250.0, -2, 1300.0],
            'zero' => [0.0, 2, 0.0],
        ];
    }

    #[DataProvider('roundCases')]
    public function test_round_is_php_version_independent(float $value, int $decimals, float $expected): void
    {
        $this->assertSame($expected, Money::round($value, $decimals));
        $this->assertSame($expected, money_round($value, $decimals));
    }
}
