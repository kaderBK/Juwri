<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services;

use App\Support\StoreClock;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Menu\Models\Menu;

/**
 * Analytics SA1 — the menu-engineering matrix. Classifies each item by popularity
 * (units ordered) × profit margin (price − cost) into Stars / Plowhorses / Puzzles /
 * Dogs, and surfaces the view-to-order ratio so high-view / low-order items (a photo,
 * description or price problem) are flagged. Margin degrades gracefully to
 * popularity-only when cost is not set.
 *
 * Quadrants (median split):
 *   Star       = high popularity + high margin
 *   Plowhorse  = high popularity + low margin
 *   Puzzle     = low popularity  + high margin
 *   Dog        = low popularity  + low margin
 */
class MenuEngineeringService
{
    public static function matrix(int $storeId, ?string $from = null, ?string $to = null): array
    {
        [$fromStart, $toEnd] = StoreClock::rangeWindow($storeId, $from, $to);
        $fromDate = $fromStart ?: now()->copy()->subDays(365);
        $toDate = $toEnd ?: now();
        $toOp = $toEnd ? '<' : '<=';

        $menus = Menu::where('store_id', $storeId)->get(['id', 'store_id', 'name', 'price_minor', 'cost_minor', 'currency_code']);
        if ($menus->isEmpty()) {
            return ['items' => [], 'has_margin' => false];
        }

        // Units ordered per item in range.
        $qty = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $storeId)
            ->where('orders.created_at', '>=', $fromDate)->where('orders.created_at', $toOp, $toDate)
            ->groupBy('order_items.item_id')
            ->select('order_items.item_id')
            ->selectRaw('SUM(quantity) as qty') // bare col — works with DB table prefix
            ->pluck('qty', 'item_id');

        // Views per item in range.
        $views = DB::table('menu_item_events')
            ->where('store_id', $storeId)
            ->where('type', 'view')
            ->where('created_at', '>=', $fromDate)->where('created_at', $toOp, $toDate)
            ->groupBy('menu_id')
            ->select('menu_id')
            ->selectRaw('COUNT(*) as c')
            ->pluck('c', 'menu_id');

        $rows = $menus->map(function ($m) use ($qty, $views) {
            $ordered = (int) ($qty[$m->id] ?? 0);
            $viewed = (int) ($views[$m->id] ?? 0);
            $margin = $m->cost !== null ? round((float) $m->price - (float) $m->cost, 2) : null;

            return [
                'menu_id' => $m->id,
                'name' => $m->getRawOriginal('name'),
                'ordered' => $ordered,
                'views' => $viewed,
                'view_to_order' => $viewed > 0 ? round($ordered / $viewed * 100, 1) : null,
                'margin' => $margin,
            ];
        })->values();

        $hasMargin = $rows->whereNotNull('margin')->isNotEmpty();
        $popMedian = self::median($rows->pluck('ordered')->all());
        $marginMedian = $hasMargin ? self::median($rows->whereNotNull('margin')->pluck('margin')->all()) : null;

        $rows = $rows->map(function ($r) use ($popMedian, $marginMedian, $hasMargin) {
            $hiPop = $r['ordered'] >= $popMedian;
            if (! $hasMargin || $r['margin'] === null) {
                $r['quadrant'] = $hiPop ? 'popular' : 'unpopular';
            } else {
                $hiMargin = $r['margin'] >= $marginMedian;
                $r['quadrant'] = $hiPop
                    ? ($hiMargin ? 'star' : 'plowhorse')
                    : ($hiMargin ? 'puzzle' : 'dog');
            }
            // Flag: viewed a lot but rarely ordered (fix photo/description/price).
            $r['underperformer'] = $r['views'] >= 10 && $r['view_to_order'] !== null && $r['view_to_order'] < 10;

            return $r;
        })->values();

        return ['items' => $rows->all(), 'has_margin' => $hasMargin];
    }

    private static function median(array $nums): float
    {
        $nums = array_values(array_filter($nums, fn ($n) => $n !== null));
        if (! $nums) {
            return 0.0;
        }
        sort($nums);
        $c = count($nums);
        $mid = intdiv($c, 2);

        return $c % 2 ? (float) $nums[$mid] : (($nums[$mid - 1] + $nums[$mid]) / 2);
    }
}
