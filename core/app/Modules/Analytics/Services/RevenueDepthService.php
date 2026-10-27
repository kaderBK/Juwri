<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Services;

use App\Models\Order;
use App\Support\StoreClock;

/**
 * Analytics SA4 — revenue depth: AOV, revenue by daypart and by branch, over a range.
 * Reuses Order->total (the one money path) over a bounded window.
 */
class RevenueDepthService
{
    public static function breakdown(int $storeId, ?string $from = null, ?string $to = null): array
    {
        [$fromStart,$toEnd] = StoreClock::rangeWindow($storeId, $from, $to);
        $fromDate = $fromStart ?: now()->copy()->subDays(30);
        $toDate = $toEnd ?: now();
        $orders = Order::where('store_id', $storeId)->where('created_at', '>=', $fromDate)->where('created_at', $toEnd ? '<' : '<=', $toDate)
            ->with(['items.menu', 'items.itemExtras.extra', 'items.variant'])->get();
        $revenue = round($orders->sum(fn ($o) => (float) $o->total), 2);
        $count = $orders->count();
        $dayparts = ['morning' => 0.0, 'afternoon' => 0.0, 'evening' => 0.0, 'night' => 0.0];
        $byBranch = [];
        foreach ($orders as $o) {
            $t = (float) $o->total;
            $h = (int) optional($o->created_at)->copy()->setTimezone(StoreClock::tz($storeId))->hour;
            $bucket = $h < 11 ? 'morning' : ($h < 17 ? 'afternoon' : ($h < 22 ? 'evening' : 'night'));
            $dayparts[$bucket] = round($dayparts[$bucket] + $t, 2);
            $bid = $o->branch_id ?: 0;
            $byBranch[$bid] = round(($byBranch[$bid] ?? 0) + $t, 2);
        }

        return [
            'revenue' => $revenue,
            'orders' => $count,
            'aov' => $count ? round($revenue / $count, 2) : 0.0,
            'by_daypart' => $dayparts,
            'by_branch' => $byBranch,
        ];
    }
}
