<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Delivery;

use App\Models\Driver;
use App\Models\Order;
use App\Support\StoreClock;
use Illuminate\Support\Carbon;

/**
 * SYSTEMS-RUN E-P2: per-driver delivery report — count, average delivery time
 * (assigned→delivered), and cash collected, over a date range.
 */
class DeliveryReportService
{
    public function perDriver(int $storeId, ?string $from = null, ?string $to = null): array
    {
        $out = [];
        foreach (Driver::where('store_id', $storeId)->get() as $driver) {
            $orders = Order::where('driver_id', $driver->id)
                ->where('delivery_status', 'delivered')
                ->tap(fn ($q) => StoreClock::whereLocalDateBetween($q, 'delivered_at', $storeId, $from, $to))
                ->get();

            $count = $orders->count();
            $totalMinutes = 0;
            $timed = 0;
            $cash = 0.0;
            foreach ($orders as $o) {
                if ($o->assigned_at && $o->delivered_at) {
                    $totalMinutes += Carbon::parse($o->assigned_at)
                        ->diffInMinutes(Carbon::parse($o->delivered_at));
                    $timed++;
                }
                if (in_array(strtolower((string) ($o->payment_method ?? $o->payment_gateway ?? '')), ['cash', 'cod'], true)) {
                    $cash += (float) $o->total;
                }
            }

            $out[] = [
                'driver' => $driver,
                'deliveries' => $count,
                'avg_minutes' => $timed > 0 ? round($totalMinutes / $timed, 1) : null,
                'cash_collected' => round($cash, 2),
            ];
        }

        return $out;
    }
}
