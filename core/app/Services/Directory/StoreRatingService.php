<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Directory;

use App\Models\StoreReview;
use Illuminate\Support\Facades\Cache;

/**
 * DIRECTORY T2-4: the ONE source for a store's public rating.
 *
 * D3: the directory rating comes ONLY from `store_reviews` — MenuReview (item
 * reviews) never blends in here, even though both tables carry a
 * `store_id`. Item ratings keep rendering on menu items exactly as
 * today (StoreController::show's $itemRatings); this service is never
 * consulted for that number.
 *
 * Cached 60s per store, bucket-of-one so a card grid's batch() call never
 * re-runs the grouped query for a store another request already warmed.
 * `bust()` invalidates a single store the moment a review's status changes
 * (DirectoryReviewController) so a moderation decision is never stale for
 * longer than one in-flight request.
 */
class StoreRatingService
{
    private const TTL = 60;

    /** @return array{avg: float|null, count: int, histogram: array<int,int>} */
    public static function forStore(int $storeId): array
    {
        return Cache::remember(
            self::cacheKey($storeId),
            self::TTL,
            fn () => self::compute([$storeId])[$storeId] ?? self::emptyRow()
        );
    }

    /**
     * Batch aggregate for a card grid — at most ONE grouped query for every id
     * not already cached, never one query per card (the pattern this mirrors
     * lives at ShellController.php:44).
     *
     * @param  array<int>  $storeIds
     * @return array<int, array{avg: float|null, count: int, histogram: array<int,int>}>
     */
    public static function batch(array $storeIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $storeIds)));
        if (empty($ids)) {
            return [];
        }

        $out = [];
        $missing = [];
        foreach ($ids as $id) {
            $cached = Cache::get(self::cacheKey($id));
            if ($cached !== null) {
                $out[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }

        if (! empty($missing)) {
            $computed = self::compute($missing);
            foreach ($missing as $id) {
                $row = $computed[$id] ?? self::emptyRow();
                Cache::put(self::cacheKey($id), $row, self::TTL);
                $out[$id] = $row;
            }
        }

        return $out;
    }

    /** Invalidate one store's cached rating — call on every moderation decision. */
    public static function bust(int $storeId): void
    {
        Cache::forget(self::cacheKey($storeId));
    }

    /**
     * One grouped, prefix-safe query (bare column names only) for every id
     * requested — the shape every caller above builds on.
     *
     * @param  array<int>  $ids
     * @return array<int, array{avg: float|null, count: int, histogram: array<int,int>}>
     */
    private static function compute(array $ids): array
    {
        $rows = StoreReview::whereIn('store_id', $ids)
            ->where('status', 'approved')
            ->selectRaw('store_id, stars, count(*) as c')
            ->groupBy('store_id', 'stars')
            ->get();

        $acc = [];
        foreach ($rows as $row) {
            $rid = (int) $row->store_id;
            $acc[$rid] ??= ['sum' => 0, 'count' => 0, 'histogram' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]];
            $star = (int) $row->stars;
            $c = (int) $row->c;
            $acc[$rid]['sum'] += $star * $c;
            $acc[$rid]['count'] += $c;
            if (isset($acc[$rid]['histogram'][$star])) {
                $acc[$rid]['histogram'][$star] = $c;
            }
        }

        $out = [];
        foreach ($acc as $rid => $r) {
            $out[$rid] = [
                'avg' => $r['count'] ? round($r['sum'] / $r['count'], 1) : null,
                'count' => $r['count'],
                'histogram' => $r['histogram'],
            ];
        }

        return $out;
    }

    private static function emptyRow(): array
    {
        return ['avg' => null, 'count' => 0, 'histogram' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]];
    }

    private static function cacheKey(int $storeId): string
    {
        return 'dir:store_rating:'.$storeId;
    }
}
