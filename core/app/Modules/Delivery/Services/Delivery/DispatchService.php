<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Delivery;

use App\Models\Driver;
use App\Models\Order;

/**
 * SYSTEMS-RUN E-P2: dispatcher intelligence — proximity-sorted driver
 * suggestions (haversine on last GPS ping) and nearest-available auto-assign.
 *
 * Availability model (P6-344): a driver is "available" only when active,
 * shift_status='online' AND active_load < capacity — the same predicate as
 * Driver::isAvailable(). Drivers whose GPS is stale (last_location_at older
 * than GPS_FRESH_MINUTES, or null) are KEPT in the suggestion list as a last
 * resort, but ranked below drivers with a recent ping so the owner panel and
 * autoAssign prefer a live one when any exists.
 */
class DispatchService
{
    /**
     * Drivers whose last_location_at is older than this are "stale" — kept in
     * the list as last resort, never chosen by autoAssign while a fresh driver
     * remains.
     */
    public const GPS_FRESH_MINUTES = 10;

    /**
     * Driver suggestions for an order, filtered to availability (active +
     * online + has spare capacity) and ranked fresh-GPS-first then by distance
     * (nulls last). Stale-GPS drivers that are still available come AFTER every
     * fresh one — autoAssign therefore never picks a stale driver unless no
     * fresh one exists.
     *
     * Each row: ['driver' => Driver, 'distance' => ?float, 'stale' => bool].
     */
    public function suggestDrivers(Order $order, int $limit = 5): array
    {
        // Only available drivers reach the panel — offline or full drivers
        // are never suggested, let alone assigned.
        $drivers = Driver::where('store_id', $order->store_id)
            ->where('active', true)
            ->where('shift_status', 'online')
            ->whereColumn('active_load', '<', 'capacity')
            ->get();

        $cutoff = now()->subMinutes(self::GPS_FRESH_MINUTES);
        $rows = [];
        foreach ($drivers as $d) {
            $stale = $d->last_location_at === null
                || $d->last_location_at->lt($cutoff);

            $rows[] = [
                'driver' => $d,
                'distance' => $this->distanceForDriver($order, $d),
                'stale' => $stale,
            ];
        }

        usort($rows, function ($a, $b) {
            // Fresh drivers before stale drivers (autoAssign's first row must
            // never be a stale-but-online driver while a fresh one exists).
            if ($a['stale'] !== $b['stale']) {
                return $a['stale'] ? 1 : -1;
            }
            $da = $a['distance'] ?? INF;
            $db = $b['distance'] ?? INF;

            return $da <=> $db;
        });

        return array_slice($rows, 0, $limit);
    }

    /** Assign the nearest available driver if the order is unassigned. Idempotent. */
    public function autoAssign(Order $order): ?Driver
    {
        if ($order->driver_id) {
            return Driver::find($order->driver_id);
        }
        // suggestDrivers filters to availability AND ranks fresh first — so
        // when it returns a row, autoAssign picks a driver that is online, has
        // spare capacity, and is either the freshest-GPS available driver or
        // (no fresh available driver left) a stale-but-available one. Offline
        // or full drivers are unreachable through this path.
        $suggestions = $this->suggestDrivers($order, 1);
        if (empty($suggestions)) {
            return null;
        }
        $driver = $suggestions[0]['driver'];

        // Atomic claim — the check-then-write above is not enough on its own. Two
        // concurrent autoAssign calls (or autoAssign racing a driver claim()/owner
        // assign) both read driver_id = null; a blind update would let the last writer
        // silently overwrite the first, losing an assignment. Only the caller that
        // flips driver_id away from NULL wins; a loser returns the driver that won.
        $claimed = Order::whereKey($order->id)->whereNull('driver_id')
            ->update(['driver_id' => $driver->id, 'assigned_at' => now()]);
        if (! $claimed) {
            return Driver::find($order->fresh()->driver_id);
        }
        $order->driver_id = $driver->id; // reflect the win on the in-memory model

        return $driver;
    }

    private function distanceForDriver(Order $order, Driver $driver): ?float
    {
        if ($order->delivery_lat === null || $order->delivery_lng === null
            || $driver->last_lat === null || $driver->last_lng === null) {
            return null;
        }

        return $this->haversine(
            (float) $driver->last_lat, (float) $driver->last_lng,
            (float) $order->delivery_lat, (float) $order->delivery_lng
        );
    }

    /** Great-circle distance in km. */
    public function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
