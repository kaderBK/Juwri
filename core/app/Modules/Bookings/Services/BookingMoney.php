<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Juwri\Core\Money\Money;

/** P3-10: booking amounts as the store shows prices (formatted in PHP, never in Blade). */
final class BookingMoney
{
    public static function format(int $minor, string $currency, int $storeId): string
    {
        return store_price_symbol(Money::majorFromMinor($minor, $currency), $storeId);
    }

    /** "45 min", "2 h", "2 h 30 min" (never "1.5 h", book §3). */
    public static function duration(int $minutes): string
    {
        $h = intdiv(max(0, $minutes), 60);
        $m = max(0, $minutes) % 60;
        if ($h === 0) {
            return ___(':count min', ['count' => $m]);
        }

        return $m === 0 ? ___(':count h', ['count' => $h]) : ___(':hours h :minutes min', ['hours' => $h, 'minutes' => $m]);
    }
}
