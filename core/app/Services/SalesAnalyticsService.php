<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Order;
use Carbon\Carbon;
use Juwri\Core\Money\Money;

/**
 * Sales summary over a date range, shared by the owner API and the staff app.
 * Revenue is summed from the live Order->total accessor (no stored total column),
 * so a bounded range is required.
 */
class SalesAnalyticsService
{
    /**
     * @param  string|null  $from  Y-m-d (default 30 days ago)
     * @param  string|null  $to  Y-m-d (default today)
     */
    public static function summary(int $storeId, ?string $from, ?string $to): array
    {
        // Parse the Y-m-d range on the STORE's clock, then convert to the app tz that
        // created_at is stored in. Bare now()/Carbon::parse() bucketed a store's "today"
        // by the platform tz — an N-hour-shifted window that dropped early-local orders
        // and leaked next-day ones for any non-UTC store — and a garbage ?from/?to 500'd
        // (no rescue). Mirrors DashboardController::todayWindow / PosController::sales.
        $appTz = config('app.timezone') ?: 'UTC';
        $tz = store_tz($storeId);
        $dec = currency_decimals(store_currency($storeId)); // 3-dec stores (KWD/OMR…) kept exact, not truncated to 2
        $fromLocal = rescue(fn () => $from ? Carbon::parse($from, $tz)->startOfDay() : Carbon::now($tz)->subDays(30)->startOfDay(), Carbon::now($tz)->subDays(30)->startOfDay(), false);
        $toLocal = rescue(fn () => $to ? Carbon::parse($to, $tz)->endOfDay() : Carbon::now($tz)->endOfDay(), Carbon::now($tz)->endOfDay(), false);
        if ($toLocal->lt($fromLocal)) {
            [$fromLocal, $toLocal] = [$toLocal->copy()->startOfDay(), $fromLocal->copy()->endOfDay()];
        }
        $fromDate = $fromLocal->copy()->setTimezone($appTz);
        $toDate = $toLocal->copy()->setTimezone($appTz);

        $orders = Order::where('store_id', $storeId)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->with(['items.menu', 'items.itemExtras.extra', 'items.variant'])
            ->get();

        $paid = $orders->where('is_paid', true);

        $topItems = $orders->flatMap->items
            ->groupBy('item_id')
            ->map(fn ($rows) => [
                'item_id' => $rows->first()->item_id,
                'name' => $rows->first()->menu?->getRawOriginal('name'),
                'quantity' => $rows->sum('quantity'),
            ])
            ->sortByDesc('quantity')
            ->take(10)
            ->values();

        // Report the FROZEN snapshot (orders.grand_total, written by snapshotTotals at
        // every order-write choke point + backfilled by orders:snapshot-totals), so a
        // closed period's revenue is immutable and does not silently drift when a menu
        // price is later edited (the live `total` accessor re-derives from current prices).
        // Fall back to the accessor only for a row whose snapshot is still NULL, so this
        // can never under-count an un-snapshotted order.
        $revenueOf = fn ($o) => $o->grand_total !== null ? (float) $o->grand_total : (float) $o->total;

        $grossRevenue = Money::round($orders->sum($revenueOf), $dec);
        $paidRevenue = Money::round($paid->sum($revenueOf), $dec);

        return [
            // Echo the range in the STORE's calendar dates the caller asked for.
            'range' => ['from' => $fromLocal->toDateString(), 'to' => $toLocal->toDateString()],
            'orders' => [
                'total' => $orders->count(),
                'paid' => $paid->count(),
                'by_status' => $orders->groupBy('status')->map->count(),
                'by_type' => $orders->groupBy('type')->map->count(),
            ],
            'revenue' => [
                'gross' => $grossRevenue,
                'paid' => $paidRevenue,
                // H10: the dashboard/analytics definition (completed OR paid). Added,
                // never replacing gross/paid — those two are a public API contract
                // (/api/v1 analytics, staff app, MCP) and must not move.
                'counted' => Money::round($orders->filter(fn ($o) => $o->countsAsRevenue())
                    ->sum($revenueOf), $dec),
                'avg_order_value' => $orders->count() ? Money::round($grossRevenue / $orders->count(), $dec) : 0,
            ],
            'top_items' => $topItems,
        ];
    }
}
