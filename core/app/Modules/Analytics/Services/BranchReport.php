<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Services;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Branch;

/**
 * P1-13 13h: the per-branch report figures (Community), moved verbatim out of BranchReportController so the ee
 * cross-branch comparison builds its rows from the very same code.
 */
final class BranchReport
{
    /** One branch's orders, revenue, average order and top item since $since. */
    public static function row(int $storeId, Branch $branch, Carbon $since): object
    {
        // P6-322: every figure is a grouped SQL query, no order is loaded into memory.
        $window = Order::where('store_id', $storeId)
            ->where('branch_id', $branch->id)
            ->where('created_at', '>=', $since);

        // Cancelled orders are not "orders placed" and never feed the top item.
        $live = (clone $window)->where('status', '!=', 'cancelled'); // = Ordering's OrderStatus::Cancelled (its Enums are not a public part)
        $orderCount = (clone $live)->count();

        // Revenue = Order::revenueSum(), the ONE sum the dashboard uses (frozen grand_total of paid/completed
        // orders), so this report and the dashboard agree for the same branch and window even after a price change.
        $revenue = round(Order::revenueSum($window), 2);
        $revenueCount = (clone $window)->revenueCounted()->count();

        // Top item for this branch by quantity (ties: first by name, deterministic).
        $topItem = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('items', 'items.id', '=', 'order_items.item_id')
            ->where('orders.store_id', $storeId)
            ->where('orders.branch_id', $branch->id)
            ->where('orders.created_at', '>=', $since)
            ->where('orders.status', '!=', 'cancelled')
            ->groupBy('items.name')
            ->orderByRaw('SUM(order_items.quantity) DESC')
            ->orderBy('items.name')
            ->limit(1)
            ->select('items.name')
            ->first();
        $topItem = $topItem ? ($topItem->name ?? '—') : null;

        return (object) [
            'branch' => $branch,
            'orders' => $orderCount,
            'revenue' => $revenue,
            'avg' => $revenueCount ? round($revenue / $revenueCount, 2) : 0,
            'top_item' => $topItem,
            'status' => $branch->statusLabel(),
        ];
    }

    /**
     * Totals over the rows (same accumulation as before: rounded per-branch revenue summed, then rounded).
     *
     * @param  Collection<int, object>  $rows
     * @return array{orders: int, revenue: float}
     */
    public static function totals(Collection $rows): array
    {
        $totals = ['orders' => 0, 'revenue' => 0.0];
        foreach ($rows as $row) {
            $totals['orders'] += $row->orders;
            $totals['revenue'] += $row->revenue;
        }
        $totals['revenue'] = round($totals['revenue'], 2);

        return $totals;
    }
}
