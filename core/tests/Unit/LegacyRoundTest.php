<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit;

use Juwri\Core\Money\LegacyRound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P1-00a: money rounding must not depend on the PHP version (8.4 changed round()).
 * Expected values are PHP 8.3 round() results, which the storefront money core
 * (mb-money.js) and stored orders follow.
 */
class LegacyRoundTest extends TestCase
{
    /** @return array<string, array{float, int, float}> */
    public static function cases(): array
    {
        return [
            '0.035 (8.4 gives 0.03)' => [0.035, 2, 0.04],
            '4.10 x 15%' => [4.10 * 0.15, 2, 0.62],
            '1.005' => [1.005, 2, 1.01],
            '2.675' => [2.675, 2, 2.68],
            '5.055' => [5.055, 2, 5.06],
            'negative half' => [-0.035, 2, -0.04],
            'three decimals (fils)' => [1.0005, 3, 1.001],
            'integer half' => [0.5, 0, 1.0],
            'below the cut' => [0.0049, 2, 0.0],
            'tiny' => [1e-20, 2, 0.0],
            'already exact' => [59.97, 2, 59.97],
            'large' => [123456789.125, 2, 123456789.13],
            'negative places' => [1250.0, -2, 1300.0],
            'zero' => [0.0, 2, 0.0],
        ];
    }

    #[DataProvider('cases')]
    public function test_rounds_like_php_8_3(float $value, int $decimals, float $expected): void
    {
        $this->assertSame($expected, LegacyRound::round($value, $decimals));
    }

    public function test_parity_fixture_rows_round_to_their_expected_values(): void
    {
        $rows = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/money-core-cases.json'), true);
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $raw = $row['type'] === 'fixed'
                ? (float) $row['rate']
                : (float) $row['subtotal'] * ((float) $row['rate'] / 100);
            $this->assertSame((float) $row['expected'], LegacyRound::round($raw, (int) $row['decimals']), json_encode($row));
        }
    }
}
