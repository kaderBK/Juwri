<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

/**
 * Collision-free phone numbers for fixtures (P6-125): a random 4-digit number collided now and then
 * (seen on pgsql in int/p5k). A per-process sequence never repeats inside a run.
 */
final class UniquePhone
{
    private static int $seq = 0;

    public static function next(string $prefix = '5'): string
    {
        self::$seq++;

        return $prefix.str_pad((string) (self::$seq % 10000), 4, '0', STR_PAD_LEFT);
    }
}
