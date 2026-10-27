<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use App\Models\Order;

/**
 * P6-236: when may a customer-facing surface show the assigned driver's
 * identity, phone and live position? Only while the delivery is actually
 * running: open order, en-route delivery status, fleet driver on shift,
 * and (for the position) a fix no older than LOCATION_MAX_AGE_MINUTES.
 * Lives in App\Support (not the Delivery module) so the storefront and the API formatters may share it.
 */
final class DriverExposure
{
    public const LOCATION_MAX_AGE_MINUTES = 10;

    private const EN_ROUTE = ['picked_up', 'out_for_delivery'];

    private const CLOSED_ORDER = ['delivered', 'completed', 'cancelled'];

    /** Driver name + phone may be shown (delivery is live). */
    public static function isLive(Order $order): bool
    {
        $driver = $order->driver;
        if (! $driver || (int) $order->driver_id !== (int) $driver->id) {
            return false;
        }
        if (! in_array((string) $order->delivery_status, self::EN_ROUTE, true)) {
            return false;
        }
        if (in_array((string) $order->status, self::CLOSED_ORDER, true) || $order->cancelled_at || $order->delivered_at) {
            return false;
        }
        // Fleet drivers carry a real shift; an offline fleet driver is off duty.
        if ($driver->is_fleet && (string) $driver->shift_status === 'offline') {
            return false;
        }

        return true;
    }

    /** The live position may be shown (live delivery + fresh fix). */
    public static function locationVisible(Order $order): bool
    {
        if (! self::isLive($order)) {
            return false;
        }
        $at = $order->driver->last_location_at;

        return $at !== null
            && $order->driver->last_lat !== null
            && $at->greaterThanOrEqualTo(now()->subMinutes(self::LOCATION_MAX_AGE_MINUTES));
    }
}
