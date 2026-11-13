<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Models\Booking;

/**
 * P3-10: the signed manage link of a booking (book §8; the API's `manage_token`). Token = "<public_id>.<signature>",
 * signature = HMAC-SHA256(app key, public_id | store id | the booking's random nonce), 40 hex chars. Changing any part
 * fails; rotating the nonce revokes every link sent before. No account needed: guests reach their booking from the
 * SMS/email link.
 */
final class ManageLink
{
    public static function token(Booking $booking): string
    {
        return $booking->public_id.'.'.self::signature($booking);
    }

    public static function url(Booking $booking, Store $store): string
    {
        return route('bookings.manage', ['slug' => $store->slug, 'token' => self::token($booking)]);
    }

    /** The booking of this store the token signs, or null (unknown, other store, or tampered). */
    public static function resolve(Store $store, string $token): ?Booking
    {
        if (! preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{40})$/', $token, $m)) {
            return null;
        }
        $booking = Booking::query()->where('store_id', $store->id)->where('public_id', $m[1])->first();
        if (! $booking instanceof Booking) {
            return null;
        }

        return hash_equals(self::signature($booking), $m[2]) ? $booking : null;
    }

    private static function signature(Booking $booking): string
    {
        $key = (string) config('app.key');

        return substr(hash_hmac('sha256', $booking->public_id.'|'.$booking->store_id.'|'.$booking->manage_nonce, $key), 0, 40);
    }
}
