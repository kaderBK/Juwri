<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\Store;

/**
 * ZERO-TOUCH Track C — heuristic prep-time prediction (ETA v1).
 *
 * estimate() answers "how long until this order is ready?" from the store's
 * own history:
 *
 *   1. Sample: last 30 days of orders with BOTH `preparing_at` and `ready_at`
 *      stamped (staff status flow records the actuals). ≥ MIN_SAMPLES or the
 *      heuristic abstains (returns the owner's static fallback, or null).
 *   2. Base: p25 / p50 / p75 of per-order prep minutes.
 *   3. Live-load multiplier: open `preparing` tickets right now vs the 30-day
 *      median concurrency, clamped 0.8×–2.0×.
 *   4. Item-count factor: + median per-item minutes for each line beyond the
 *      store's median line count (small additive, clamped).
 *   5. Rounded to friendly 5-minute bounds → "20–30 min".
 *
 * The heavy aggregate is Cache::remember'd 5 minutes per store; the live load
 * count 60 seconds — so the polling tracking page stays cheap.
 *
 * SEAM (future ML): this class is the single swap point. Every surface calls
 * EtaEstimator::estimate()/forOrder() and consumes the EtaRange DTO — replace
 * the internals (or the whole class behind a container binding) without
 * touching any surface.
 *
 * NAMING: unrelated to App\Services\Fiscal\Eta (Egyptian Tax Authority).
 * All option/response keys use `prep_eta*` to keep the spaces separate.
 */
class EtaEstimator
{
    /** Heuristic abstains below this many completed samples (locked design). */
    public const MIN_SAMPLES = 20;

    /** Load multiplier clamp (locked design). */
    public const LOAD_MIN = 0.8;

    public const LOAD_MAX = 2.0;

    /** Sanity window for a single order's prep time, minutes. */
    public const PREP_MIN_MINUTES = 1;

    public const PREP_MAX_MINUTES = 240;

    /** Item-count factor clamps: per extra line + total additive, minutes. */
    public const PER_ITEM_MIN = 0.5;

    public const PER_ITEM_MAX = 5.0;

    public const ITEM_FACTOR_CAP = 15.0;

    /** Aggregate cache TTL (seconds) + live-load cache TTL. */
    public const STATS_TTL = 300;

    public const LOAD_TTL = 60;

    /** Statuses an order can be in BEFORE the kitchen finishes; ETA hides after. */
    public const SHOW_STATUSES = ['unpaid', 'pending', 'new', 'preparing'];

    /** Owner static fallback option, "25-35" (validated ^\d{1,3}-\d{1,3}$). */
    public const STATIC_OPTION = 'prep_eta_static';

    /**
     * Best available estimate for a store: heuristic first, owner static
     * fallback second, null when neither is available (= show nothing).
     *
     * @param  int|null  $itemCount  line count of the order being estimated
     */
    public static function estimate(Store|int $store, ?int $itemCount = null): ?EtaRange
    {
        $storeId = $store instanceof Store ? (int) $store->id : (int) $store;

        return self::heuristic($storeId, $itemCount) ?? self::staticRange($storeId);
    }

    /**
     * Estimate for a concrete order — null once the order is past preparing
     * (ready/completed/cancelled show actuals instead, never a stale ETA).
     */
    public static function forOrder(Order $order): ?EtaRange
    {
        // A freshly-created order may not have refreshed the DB's default
        // status into the model ('' here) — brand-new is by definition
        // pre-kitchen, so it estimates like 'pending'.
        $status = (string) $order->status;
        if ($status !== '' && ! in_array($status, self::SHOW_STATUSES, true)) {
            return null;
        }
        if ($order->ready_at !== null || $order->completed_at !== null || $order->cancelled_at !== null) {
            return null;
        }

        $itemCount = $order->relationLoaded('items')
            ? $order->items->count()
            : $order->items()->count();

        return self::estimate((int) $order->store_id, $itemCount);
    }

    /** History-based estimate; null when the store lacks ≥ MIN_SAMPLES. */
    public static function heuristic(int $storeId, ?int $itemCount = null): ?EtaRange
    {
        $stats = self::stats($storeId);
        if (! ($stats['ok'] ?? false)) {
            return null;
        }

        $multiplier = self::loadMultiplier($storeId, (float) $stats['median_concurrency']);

        $extra = 0.0;
        if ($itemCount !== null && $itemCount > $stats['median_items']) {
            $extra = min(
                self::ITEM_FACTOR_CAP,
                ($itemCount - $stats['median_items']) * (float) $stats['per_item_p50']
            );
        }

        $lo = (float) $stats['p25'] * $multiplier + $extra;
        $mid = (float) $stats['p50'] * $multiplier + $extra;
        $hi = (float) $stats['p75'] * $multiplier + $extra;

        [$lo5, $hi5] = self::roundBounds($lo, $hi);

        return new EtaRange($lo5, max(1, (int) round($mid)), $hi5, (int) $stats['samples'], 'heuristic');
    }

    /** Owner-set fallback range from the `prep_eta_static` post option. */
    public static function staticRange(int $storeId): ?EtaRange
    {
        $raw = trim((string) store_options($storeId, self::STATIC_OPTION, ''));
        if ($raw === '' || ! preg_match('/^(\d{1,3})-(\d{1,3})$/', $raw, $m)) {
            return null;
        }

        $lo = (int) $m[1];
        $hi = (int) $m[2];
        if ($lo < 1 || $hi < 1) {
            return null;
        }
        if ($lo > $hi) {
            [$lo, $hi] = [$hi, $lo];
        }

        return new EtaRange($lo, (int) round(($lo + $hi) / 2), $hi, 0, 'static');
    }

    /**
     * 30-day per-store aggregates, cached 5 minutes. Always returns an array
     * (['ok' => false] when under-sampled) so the miss is cached too.
     *
     * DB-prefix safe: pure query builder / Eloquent, no raw table names.
     */
    public static function stats(int $storeId): array
    {
        return Cache::remember('prep_eta:stats:'.$storeId, self::STATS_TTL, function () use ($storeId) {
            $rows = Order::query()
                ->select(['id', 'store_id', 'preparing_at', 'ready_at'])
                ->withCount('items')
                ->where('store_id', $storeId)
                ->where('status', '!=', 'cancelled')
                ->whereNotNull('preparing_at')
                ->whereNotNull('ready_at')
                ->where('preparing_at', '>=', now()->subDays(30))
                ->orderBy('preparing_at')
                ->get();

            $prepMinutes = [];
            $itemCounts = [];
            $perItemMinutes = [];
            $intervals = []; // [startTs, endTs] for the concurrency sweep

            foreach ($rows as $row) {
                if (! $row->preparing_at || ! $row->ready_at) {
                    continue;
                }
                $mins = $row->preparing_at->diffInSeconds($row->ready_at, false) / 60.0;
                if ($mins < self::PREP_MIN_MINUTES || $mins > self::PREP_MAX_MINUTES) {
                    continue; // negative/zero or stuck-ticket outliers poison percentiles
                }
                $lines = max(1, (int) $row->items_count);
                $prepMinutes[] = $mins;
                $itemCounts[] = $lines;
                $perItemMinutes[] = $mins / $lines;
                $intervals[] = [$row->preparing_at->getTimestamp(), $row->ready_at->getTimestamp()];
            }

            if (count($prepMinutes) < self::MIN_SAMPLES) {
                return ['ok' => false, 'samples' => count($prepMinutes)];
            }

            sort($prepMinutes);
            sort($itemCounts);
            sort($perItemMinutes);

            return [
                'ok' => true,
                'samples' => count($prepMinutes),
                'p25' => self::percentile($prepMinutes, 0.25),
                'p50' => self::percentile($prepMinutes, 0.50),
                'p75' => self::percentile($prepMinutes, 0.75),
                'median_items' => self::percentile($itemCounts, 0.50),
                'per_item_p50' => min(
                    self::PER_ITEM_MAX,
                    max(self::PER_ITEM_MIN, self::percentile($perItemMinutes, 0.50))
                ),
                'median_concurrency' => self::medianConcurrency($intervals),
            ];
        });
    }

    /** Open preparing tickets right now vs historical median → clamped multiplier. */
    protected static function loadMultiplier(int $storeId, float $medianConcurrency): float
    {
        $live = (int) Cache::remember('prep_eta:load:'.$storeId, self::LOAD_TTL, function () use ($storeId) {
            return Order::query()
                ->where('store_id', $storeId)
                ->where('status', 'preparing')
                ->count();
        });

        $ratio = $live / max(1.0, $medianConcurrency);

        return min(self::LOAD_MAX, max(self::LOAD_MIN, $ratio));
    }

    /**
     * Median number of tickets simultaneously in preparation, from historical
     * [start, end] intervals. Event sweep (O(n log n)): depth is recorded at
     * every ticket start (including the ticket itself).
     *
     * @param  array<int, array{0:int, 1:int}>  $intervals
     */
    public static function medianConcurrency(array $intervals): float
    {
        if ($intervals === []) {
            return 1.0;
        }

        $events = [];
        foreach ($intervals as [$start, $end]) {
            $events[] = [$start, 1];
            $events[] = [$end, -1];
        }
        usort($events, function (array $a, array $b) {
            // Ends before starts at the same timestamp: a ticket finishing the
            // second another begins does not overlap it.
            return $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0];
        });

        $depth = 0;
        $depthsAtStart = [];
        foreach ($events as [, $delta]) {
            $depth += $delta;
            if ($delta === 1) {
                $depthsAtStart[] = $depth;
            }
        }

        sort($depthsAtStart);

        return max(1.0, self::percentile($depthsAtStart, 0.50));
    }

    /** Linear-interpolated percentile of a pre-sorted numeric array. */
    public static function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return (float) $sorted[0];
        }

        $rank = $p * ($n - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);
        $weight = $rank - $lower;

        return (float) $sorted[$lower] * (1 - $weight) + (float) $sorted[$upper] * $weight;
    }

    /** Round to friendly 5-minute bounds; lower ≥ 5 and a real (non-empty) range. */
    public static function roundBounds(float $lo, float $hi): array
    {
        $lo5 = max(5, (int) (floor($lo / 5) * 5));
        $hi5 = (int) (ceil($hi / 5) * 5);
        if ($hi5 <= $lo5) {
            $hi5 = $lo5 + 5;
        }

        return [$lo5, $hi5];
    }

    /** Drop a store's cached aggregates (tests / owner setting changes). */
    public static function forget(int $storeId): void
    {
        Cache::forget('prep_eta:stats:'.$storeId);
        Cache::forget('prep_eta:load:'.$storeId);
    }
}
