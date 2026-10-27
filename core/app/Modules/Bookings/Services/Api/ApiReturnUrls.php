<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services\Api;

use Illuminate\Support\Facades\Cache;
use Juwri\Modules\Bookings\Models\Booking;

/**
 * P3-06b phase D (API-2): where the core-hosted deposit page sends a headless client's customer afterwards — the
 * `payment.return_url` of `POST /bookings`, whose origin the storefront API checked against the token's allowed
 * origins. Kept for the booking's payment window (+ a margin) and copied into the deposit payment when the customer
 * starts paying (DepositService::start → `details.client_return_url`, followed by Settlement). A missing entry only
 * means the customer lands on the store's booking page instead.
 */
final class ApiReturnUrls
{
    private const MINUTES = 30;

    public static function remember(Booking $booking, string $url): void
    {
        Cache::put(self::key($booking), $url, now()->addMinutes(self::MINUTES));
    }

    public static function for(Booking $booking): ?string
    {
        $url = Cache::get(self::key($booking));

        return is_string($url) && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    private static function key(Booking $booking): string
    {
        return 'bookings:api-return:'.$booking->store_id.':'.$booking->id;
    }
}
