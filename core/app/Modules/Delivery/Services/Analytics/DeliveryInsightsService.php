<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Analytics;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * GAPS-RUN-2 B5-P1: delivery demand insights without fake precision.
 *  - heatGrid: weekday × hour delivery-order counts over an N-week window.
 *  - topAreas: most common normalized address tokens (no geocoding needed).
 *  - courierScatter: real map points from courier_deliveries GPS where present.
 * Tenant-scoped by store_id.
 */
class DeliveryInsightsService
{
    /** Address tokens too generic to be an "area". */
    private const STOP_TOKENS = ['st', 'street', 'rd', 'road', 'ave', 'avenue', 'apt', 'flat', 'floor', 'no', 'building', 'block', 'unit', 'the', 'and'];

    /**
     * 7×24 grid of delivery-order counts. weekday 0=Sunday..6=Saturday (Carbon
     * dayOfWeek), hour 0..23. Returns ['grid'=>int[7][24], 'max'=>int, 'total'=>int].
     */
    public function heatGrid(int $storeId, int $weeks = 8): array
    {
        $since = now()->subWeeks(max(1, $weeks));
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        $max = 0;
        $total = 0;

        Order::query()
            ->where('store_id', $storeId)
            ->where('type', 'delivery')
            ->where('created_at', '>=', $since)
            ->select('created_at')
            ->chunk(1000, function ($orders) use (&$grid, &$max, &$total) {
                foreach ($orders as $o) {
                    $c = Carbon::parse($o->created_at);
                    $w = (int) $c->dayOfWeek;
                    $h = (int) $c->hour;
                    $grid[$w][$h]++;
                    $total++;
                    if ($grid[$w][$h] > $max) {
                        $max = $grid[$w][$h];
                    }
                }
            });

        return ['grid' => $grid, 'max' => $max, 'total' => $total];
    }

    /**
     * Top delivery areas by normalized address token frequency.
     *
     * @return array<int, array{token:string, count:int}>
     */
    public function topAreas(int $storeId, int $weeks = 8, int $limit = 10): array
    {
        $since = now()->subWeeks(max(1, $weeks));
        $counts = [];

        Order::query()
            ->where('store_id', $storeId)
            ->where('type', 'delivery')
            ->where('created_at', '>=', $since)
            ->whereNotNull('address')
            ->select('address')
            ->chunk(1000, function ($orders) use (&$counts) {
                foreach ($orders as $o) {
                    foreach ($this->tokens((string) $o->address) as $t) {
                        $counts[$t] = ($counts[$t] ?? 0) + 1;
                    }
                }
            });

        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, $limit, true) as $token => $count) {
            $out[] = ['token' => $token, 'count' => $count];
        }

        return $out;
    }

    /**
     * Real courier GPS points (only where courier_deliveries carry coordinates).
     *
     * @return array<int, array{lat:float, lng:float}>
     */
    public function courierScatter(int $storeId, int $limit = 500): array
    {
        return \DB::table('courier_deliveries')
            ->where('store_id', $storeId)
            ->whereNotNull('courier_lat')
            ->whereNotNull('courier_lng')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['courier_lat', 'courier_lng'])
            ->map(fn ($r) => ['lat' => (float) $r->courier_lat, 'lng' => (float) $r->courier_lng])
            ->all();
    }

    /** @return array<int, string> normalized, de-noised address tokens */
    private function tokens(string $address): array
    {
        $address = Str::lower($address);
        $parts = preg_split('/[\s,]+/', $address) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim(preg_replace('/[^a-z]/', '', $p));
            if (strlen($p) < 3 || in_array($p, self::STOP_TOKENS, true)) {
                continue;
            }
            $out[$p] = true; // dedupe per address so one order counts a token once
        }

        return array_keys($out);
    }
}
