<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Analytics;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\StoreClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Menu\Models\Menu;

/**
 * GAPS-RUN-2 B5-P2: lightweight demand forecasting — no ML infra, pure PHP.
 *  - Next-7-days orders + revenue via a recency-weighted same-weekday moving
 *    average (last up to 8 same weekdays, newer weighted higher).
 *  - Intraday hourly curve (share of orders per hour) over the trailing window.
 *  - Item prep quantities: each item's trailing-28-day per-day average scaled to
 *    the next-day order forecast (PMIX, never per-item models).
 *  - Staffing suggestion: forecast covers ÷ covers_per_staff.
 * Cold-start honest: < ~2 weeks of data → ['insufficient' => true].
 */
class ForecastService
{
    private const WINDOW_DAYS = 56;   // 8 weeks of same-weekday history

    private const MIN_DATA_DAYS = 14; // below this we don't fabricate a forecast

    private const SAME_WEEKDAY_SAMPLES = 8;

    public function cached(int $storeId): array
    {
        return Cache::get($this->cacheKey($storeId)) ?? $this->compute($storeId);
    }

    public function refresh(int $storeId): array
    {
        $result = $this->compute($storeId);
        Cache::put($this->cacheKey($storeId), $result, now()->addHours(30));

        return $result;
    }

    public function compute(int $storeId, ?Carbon $today = null): array
    {
        $now = $today ? $today->copy()->startOfDay() : Carbon::instance(StoreClock::now($storeId)->startOfDay());
        $from = $now->copy()->subDays(self::WINDOW_DAYS);

        // Daily count + revenue over the window.
        $daily = []; // 'Y-m-d' => ['count'=>int,'rev'=>float]
        Order::query()
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $from->copy()->setTimezone(config('app.timezone') ?: 'UTC'))
            ->where('created_at', '<', $now->copy()->setTimezone(config('app.timezone') ?: 'UTC'))
            ->with('items.menu')
            ->chunk(1000, function ($orders) use (&$daily, $storeId) {
                foreach ($orders as $o) {
                    $d = StoreClock::localDate($storeId, $o->created_at);
                    $daily[$d]['count'] = ($daily[$d]['count'] ?? 0) + 1;
                    $daily[$d]['rev'] = ($daily[$d]['rev'] ?? 0) + (float) $o->total;
                }
            });

        if (count($daily) < self::MIN_DATA_DAYS) {
            return ['insufficient' => true, 'days_of_data' => count($daily)];
        }

        // Bucket daily values by weekday (0=Sun..6=Sat), chronological.
        $byWeekday = array_fill(0, 7, []);
        ksort($daily);
        foreach ($daily as $date => $v) {
            $w = (int) Carbon::parse($date)->dayOfWeek;
            $byWeekday[$w][] = ['count' => $v['count'], 'rev' => $v['rev']];
        }

        // Next 7 days.
        $forecast = [];
        for ($i = 1; $i <= 7; $i++) {
            $day = $now->copy()->addDays($i);
            $w = (int) $day->dayOfWeek;
            $forecast[] = [
                'date' => $day->toDateString(),
                'weekday' => $day->format('D'),
                'orders' => (int) round($this->weightedSameWeekday($byWeekday[$w], 'count')),
                'revenue' => round($this->weightedSameWeekday($byWeekday[$w], 'rev'), 2),
            ];
        }

        return [
            'insufficient' => false,
            'days_of_data' => count($daily),
            'forecast' => $forecast,
            'hourly' => $this->hourlyCurve($storeId, $from, $now),
            'prep' => $this->itemPrep($storeId, $now, $forecast[0]['orders'] ?? 0),
            'staffing' => $this->staffing($storeId, $forecast),
        ];
    }

    /** Recency-weighted average of the last N same-weekday samples (newest weighted highest). */
    private function weightedSameWeekday(array $samples, string $field): float
    {
        if (empty($samples)) {
            return 0.0;
        }
        $recent = array_slice($samples, -self::SAME_WEEKDAY_SAMPLES);
        $num = 0.0;
        $den = 0.0;
        foreach (array_values($recent) as $i => $s) {
            $weight = $i + 1; // oldest=1 … newest=k
            $num += $s[$field] * $weight;
            $den += $weight;
        }

        return $den > 0 ? $num / $den : 0.0;
    }

    /** Share of orders per hour (0..23) over the window. */
    private function hourlyCurve(int $storeId, Carbon $from, Carbon $now): array
    {
        $hours = array_fill(0, 24, 0);
        $total = 0;
        Order::query()
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $from->copy()->setTimezone(config('app.timezone') ?: 'UTC'))
            ->where('created_at', '<', $now->copy()->setTimezone(config('app.timezone') ?: 'UTC'))
            ->select('created_at')
            ->chunk(1000, function ($orders) use (&$hours, &$total, $storeId) {
                foreach ($orders as $o) {
                    $hours[(int) Carbon::parse($o->created_at)->setTimezone(StoreClock::tz($storeId))->hour]++;
                    $total++;
                }
            });

        if ($total === 0) {
            return [];
        }
        $curve = [];
        foreach ($hours as $h => $c) {
            $curve[$h] = round($c / $total, 4);
        }

        return $curve;
    }

    /** Top-10 item prep counts: trailing-28-day per-day average scaled to next-day orders. */
    private function itemPrep(int $storeId, Carbon $now, int $nextDayOrders): array
    {
        $from28 = $now->copy()->subDays(28);

        $orders28 = Order::where('store_id', $storeId)
            ->where('created_at', '>=', $from28)->where('created_at', '<', $now)->count();
        if ($orders28 === 0 || $nextDayOrders === 0) {
            return [];
        }

        $p = DB::getTablePrefix(); // raw refs aren't prefixed
        $itemQty = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $storeId)
            ->where('orders.created_at', '>=', $from28)
            ->where('orders.created_at', '<', $now)
            ->selectRaw("{$p}order_items.item_id, SUM({$p}order_items.quantity) as qty")
            ->groupBy('order_items.item_id')
            ->pluck('qty', 'item_id');

        if ($itemQty->isEmpty()) {
            return [];
        }

        $names = Menu::whereIn('id', $itemQty->keys())->pluck('name', 'id');

        $prep = [];
        foreach ($itemQty as $itemId => $qty) {
            // per-day average × next-day order forecast ÷ historical daily orders
            $perDay = ((float) $qty / max(1, $orders28)) * $nextDayOrders;
            $prep[] = [
                'item_id' => (int) $itemId,
                'name' => $names[$itemId] ?? ('#'.$itemId),
                'qty' => (int) round($perDay),
            ];
        }
        usort($prep, fn ($a, $b) => $b['qty'] <=> $a['qty']);

        return array_slice($prep, 0, 10);
    }

    /** Staffing suggestion per forecast day: covers ÷ covers_per_staff. */
    private function staffing(int $storeId, array $forecast): array
    {
        $coversPerStaff = max(1, (int) (store_options($storeId, 'covers_per_staff') ?: 20));
        $out = [];
        foreach ($forecast as $f) {
            $out[] = [
                'date' => $f['date'],
                'staff' => max(1, (int) ceil($f['orders'] / $coversPerStaff)),
            ];
        }

        return $out;
    }

    private function cacheKey(int $storeId): string
    {
        return "forecast:{$storeId}";
    }
}
