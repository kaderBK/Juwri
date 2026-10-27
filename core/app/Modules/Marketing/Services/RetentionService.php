<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Services;

use App\Models\Order;
use Carbon\Carbon;

/**
 * Analytics SA5 — repeat-customer retention / RFM. Uses orders.customer_id to measure
 * the real returning-diner rate and recency/frequency/monetary per customer.
 */
class RetentionService
{
    public static function summary(int $storeId, ?string $from = null, ?string $to = null): array
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->copy()->subDays(90);
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now();
        $orders = Order::where('store_id', $storeId)->whereNotNull('customer_id')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->with(['items.menu', 'items.itemExtras.extra', 'items.variant'])->get();
        $byCustomer = $orders->groupBy('customer_id');
        $total = $byCustomer->count();
        $returning = $byCustomer->filter(fn ($o) => $o->count() > 1)->count();
        $rfm = $byCustomer->map(function ($set) {
            $last = $set->max('created_at');

            return [
                'recency_days' => $last ? (int) Carbon::parse($last)->diffInDays(now()) : null,
                'frequency' => $set->count(),
                'monetary' => round($set->sum(fn ($o) => (float) $o->total), 2),
            ];
        });

        return [
            'customers' => $total,
            'returning_customers' => $returning,
            'returning_rate' => $total ? round($returning / $total * 100, 1) : 0.0,
            'rfm' => $rfm,
        ];
    }
}
