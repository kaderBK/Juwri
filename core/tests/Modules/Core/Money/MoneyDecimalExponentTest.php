<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use Brick\Math\Exception\NumberFormatException;
use Juwri\Core\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * P1-18 follow-up (A): Money::decimal() must not let a numeric-looking string drive brick/math into a huge allocation.
 * "1e999999999999" is numeric to PHP; brick expands the exponent into the number and dies with an uncatchable memory
 * fatal. Huge exponents are refused like any malformed number (NumberFormatException); small ones (what PHP prints when a
 * float is cast to a string) stay exact.
 */
class MoneyDecimalExponentTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function hugeExponents(): array
    {
        return [
            'plain huge' => ['1e999999999999'],
            'negative huge' => ['1e-999999999999'],
            'upper E signed' => ['+1.5E+999999999999'],
            'leading dot' => ['.5e9999999'],
            'trailing dot' => ['5.e100'],
            'negative amount' => ['-7e51'],
            'one past the limit' => ['1e51'],
            'one past the limit negative' => ['1e-51'],
            'beyond int range' => ['1e99999999999999999999999'],
            'padded' => ['  1e999999999999  '],
        ];
    }

    /** @dataProvider hugeExponents */
    #[\PHPUnit\Framework\Attributes\DataProvider('hugeExponents')]
    public function test_huge_exponents_are_refused_without_a_fatal(string $value): void
    {
        $this->expectException(NumberFormatException::class);
        Money::decimal($value);
    }

    public function test_round_and_other_entry_points_refuse_them_too(): void
    {
        $this->expectException(NumberFormatException::class);
        Money::round('1e999999999999', 2);
    }

    public function test_small_exponent_strings_stay_exact(): void
    {
        $this->assertTrue(Money::decimal('1.0E-5')->isEqualTo('0.00001'));
        $this->assertTrue(Money::decimal('1E+15')->isEqualTo('1000000000000000'));
        $this->assertTrue(Money::decimal('1.5e3')->isEqualTo('1500'));
        $this->assertTrue(Money::decimal('-2.5E-3')->isEqualTo('-0.0025'));
        $this->assertTrue(Money::decimal('1e50')->isEqualTo('1'.str_repeat('0', 50)), 'the limit itself is allowed');
        $this->assertTrue(Money::decimal('1e-50')->isGreaterThan(0));
        $this->assertTrue(Money::decimal(' 1e2 ')->isEqualTo('100'));
    }

    public function test_floats_never_go_through_string_exponent_parsing(): void
    {
        $this->assertTrue(Money::decimal(1.0E-5)->isEqualTo('0.00001'));
        $this->assertTrue(Money::decimal(1.0E+25)->isEqualTo('10000000000000000000000000'));
        $this->assertTrue(Money::decimal(2.5E-10)->isEqualTo('0.00000000025'));
        $this->assertTrue(Money::decimal(-1.0E-7)->isEqualTo('-0.0000001'));
        $this->assertTrue(Money::decimal(0.1 + 0.2)->isEqualTo('0.3'), 'float read at 15 significant digits');
        // the largest finite float does not blow up either
        $this->assertTrue(Money::decimal(PHP_FLOAT_MAX)->isGreaterThan(0));
        $this->assertTrue(Money::decimal(PHP_FLOAT_MIN)->isGreaterThan(0));
        // a float cast to string and read back is the same amount as the float
        foreach ([1.0E-5, 1.0E+15, 2.5E-8, 12345678901234.0, 0.00001234] as $f) {
            $this->assertTrue(Money::decimal((string) $f)->isEqualTo(Money::decimal($f)), (string) $f);
        }
    }

    public function test_existing_behaviour_is_unchanged(): void
    {
        $this->assertSame('0', (string) Money::decimal(''));
        $this->assertSame('12.50', (string) Money::decimal('12.50'));
        $this->assertSame('0.1', (string) Money::decimal(0.1));
        $this->assertSame('5', (string) Money::decimal(5));
        $this->expectException(NumberFormatException::class);
        Money::decimal('abc');
    }
}
