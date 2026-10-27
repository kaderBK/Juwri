<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Delivery;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;

/**
 * SYSTEMS-RUN E-P2: proof of delivery. Owner picks a mode per restaurant
 * (post option delivery_pod_mode: none|photo|otp). OTP is a 4-digit code shown
 * on the customer tracking page; the driver enters it to confirm.
 */
class PodService
{
    public static function mode(int $storeId): string
    {
        $m = (string) store_options($storeId, 'delivery_pod_mode', 'none');

        return in_array($m, ['none', 'photo', 'otp'], true) ? $m : 'none';
    }

    /** Ensure an OTP exists for the order (idempotent). Returns the code. */
    public static function ensureOtp(Order $order): string
    {
        if (! $order->pod_otp) {
            $order->update(['pod_otp' => (string) random_int(1000, 9999)]);
        }

        return (string) $order->pod_otp;
    }

    /** Wrong entries allowed per code, then the code is replaced and entering is locked for a while. */
    public const MAX_ATTEMPTS = 5;

    public const LOCK_SECONDS = 1800;

    private static function failKey(Order $order): string
    {
        return 'pod_otp_fails:'.$order->id;
    }

    /** Too many wrong entries: the 4-digit code cannot be tried again for a while. */
    public static function locked(Order $order): bool
    {
        return (int) Cache::get(self::failKey($order), 0) >= self::MAX_ATTEMPTS;
    }

    public static function verifyOtp(Order $order, string $entered): bool
    {
        if (self::locked($order)) {
            return false;
        }
        if ($order->pod_otp && hash_equals((string) $order->pod_otp, trim($entered))) {
            Cache::forget(self::failKey($order));
            $order->update(['pod_verified_at' => now()]);

            return true;
        }
        // a 4-digit code with no cap is guessed in minutes: after MAX_ATTEMPTS wrong entries the code is replaced
        // (the customer's tracking page shows the new one) and entering is locked for LOCK_SECONDS
        $fails = (int) Cache::get(self::failKey($order), 0) + 1;
        Cache::put(self::failKey($order), $fails, self::LOCK_SECONDS);
        if ($fails >= self::MAX_ATTEMPTS && $order->pod_otp) {
            $order->update(['pod_otp' => (string) random_int(1000, 9999)]);
        }

        return false;
    }

    public static function storePhoto(Order $order, string $path): void
    {
        $order->update(['pod_photo' => $path, 'pod_verified_at' => now()]);
    }

    /** Is POD satisfied for this order given the restaurant's mode? */
    public static function satisfied(Order $order): bool
    {
        $mode = self::mode((int) $order->store_id);
        if ($mode === 'none') {
            return true;
        }
        if ($mode === 'photo') {
            return (bool) $order->pod_photo;
        }

        return $order->pod_verified_at !== null;
    }
}
