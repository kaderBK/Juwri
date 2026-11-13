<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use Juwri\Modules\LegacyCompat\Import\LegacyMoney;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Importer money converter: source amounts to integer minor units, rounded half up once (no double rounding), overflow refused.
 */
class LegacyMoneyTest extends TestCase
{
    /**
     * @return array<string, array{mixed, int, int|null}>
     */
    public static function minorAmountProvider(): array
    {
        return [
            'exponent 2: rounded cents' => [9.99, 2, 999],
            'exponent 2: binary noise trimmed' => [0.1 + 0.2, 2, 30],
            'exponent 2: half up at 1.005' => [1.005, 2, 101],
            'exponent 2: below half at 1.004' => [1.004, 2, 100],
            'exponent 2: half up at 2.675' => [2.675, 2, 268],
            'exponent 2: zero' => [0, 2, 0],
            'exponent 2: integer source' => [10, 2, 1000],
            'exponent 2: numeric string' => ['12.50', 2, 1250],
            'exponent 2: numeric string with spaces' => [' 7.1 ', 2, 710],
            'exponent 2: negative half up' => [-1.005, 2, -101],
            'exponent 2: negative below half' => [-0.004, 2, 0],
            'exponent 3: thousandths rounded' => [1.2345, 3, 1235],
            'exponent 3: half up at 0.0005' => [0.0005, 3, 1],
            'exponent 3: trailing zero scaling' => [12.3, 3, 12300],
            'exponent 0: half up at 99.5' => [99.5, 0, 100],
            'exponent 0: below half at 99.4' => [99.4, 0, 99],
            'exponent 0: integer passthrough' => [1500, 0, 1500],
            'exponent 2: null' => [null, 2, null],
            'exponent 2: empty string' => ['', 2, null],
            'exponent 2: non numeric string' => ['abc', 2, null],
            'exponent 2: true' => [true, 2, null],
            'exponent 2: false' => [false, 2, null],
            'exponent 2: empty array' => [[], 2, null],
            'exponent 2: comma decimal string' => ['1,50', 2, null],
            'single rounding: 1.00499 float stays below half' => [1.00499, 2, 100],
            'single rounding: 1.00499 decimal text stays below half' => ['1.00499', 2, 100],
            'single rounding: 1.0049999 decimal text' => ['1.0049999', 2, 100],
            'decimal text rounded as text beyond float precision' => ['12345678901234567.895', 2, 1234567890123456790],
            'largest amount that fits' => ['92233720368547758.07', 2, PHP_INT_MAX],
            'overflow refused, not clamped' => ['92233720368547758.08', 2, null],
            'overflow refused for a huge float' => [1e20, 2, null],
            'leading dot decimal text' => ['.5', 2, 50],
            'exponent notation text' => ['1e3', 2, 100000],
            'twelve digit float keeps its cents' => [123456789012.345, 2, 12345678901235],
            'infinite float refused' => [INF, 2, null],
            'exponent clamp: negative exponent becomes zero' => [1.5, -1, 2],
            'exponent clamp: high exponent becomes six' => [1, 9, 1000000],
        ];
    }

    #[DataProvider('minorAmountProvider')]
    public function test_to_minor_converts_amounts(mixed $amount, int $exponent, ?int $expected): void
    {
        $this->assertSame($expected, LegacyMoney::toMinor($amount, $exponent));
    }

    public function test_result_is_int_not_float(): void
    {
        $this->assertIsInt(LegacyMoney::toMinor(19.99, 2));
    }

    public function test_locale_free_normalises_a_decimal_comma(): void
    {
        $this->assertSame('1.005', LegacyMoney::localeFree('1,005'));
        $this->assertSame('-1.5E+20', LegacyMoney::localeFree('-1,5E+20'));
        $this->assertSame('12.5', LegacyMoney::localeFree('12.5'));
    }

    public function test_float_amounts_are_converted_the_same_under_a_decimal_comma_locale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        $comma = setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE.utf8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR.utf8', 'fr_FR');
        if ($comma === false) {
            $this->markTestSkipped('no decimal-comma locale installed (localeFree() is covered above)');
        }
        try {
            $this->assertSame(',', localeconv()['decimal_point']);
            $this->assertSame(101, LegacyMoney::toMinor(1.005, 2));
            $this->assertSame(100, LegacyMoney::toMinor(1.00499, 2));
            $this->assertSame(1235, LegacyMoney::toMinor(1.2345, 3));
            $this->assertNull(LegacyMoney::toMinor(1e20, 2));
            $this->assertSame(12345678901235, LegacyMoney::toMinor(123456789012.345, 2));
        } finally {
            setlocale(LC_NUMERIC, (string) $previous);
        }
    }
}
