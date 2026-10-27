<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use Tests\TestCase;

/**
 * BRAND-ROW B1 — follower-count display helper. <1000 raw, <100k one-decimal
 * "X.Yk" (trailing .0 trimmed), else whole "Xk"; visibility threshold is 10.
 */
class FollowerCountFormatTest extends TestCase
{
    public static function cases(): array
    {
        return [
            [0, '0'],
            [9, '9'],
            [10, '10'],
            [999, '999'],
            [1000, '1k'],
            [1234, '1.2k'],
            [12500, '12.5k'],
            [125000, '125k'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function test_format_follower_count(int $in, string $expected): void
    {
        $this->assertSame($expected, format_follower_count($in));
    }

    public function test_negative_clamps_to_zero(): void
    {
        $this->assertSame('0', format_follower_count(-5));
    }

    public function test_threshold_is_single_sourced_at_ten(): void
    {
        $this->assertSame(10, follower_count_min());
    }
}
