<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Crm;

use App\Models\Customer;

/**
 * SYSTEMS-RUN C-P1: RFM scoring. Recency (days since last order), Frequency
 * (orders_count), Monetary (total_spent) each scored 1..5 by quintile across the
 * restaurant's customer base. Segment label derived from the R/F/M triple.
 */
class RfmService
{
    /** @return array{scores: array<int,array>, segments: array<string,int>} */
    public function compute(int $storeId): array
    {
        $customers = Customer::where('store_id', $storeId)
            ->where('orders_count', '>', 0)->get();

        if ($customers->isEmpty()) {
            return ['scores' => [], 'segments' => []];
        }

        $now = now();
        $recency = [];
        $frequency = [];
        $monetary = [];
        foreach ($customers as $c) {
            $recency[$c->id] = $c->last_order_at ? $now->diffInDays($c->last_order_at) : 99999;
            $frequency[$c->id] = (int) $c->orders_count;
            $monetary[$c->id] = (float) $c->total_spent;
        }

        // Recency: lower is better → invert quintile.
        $rScore = $this->quintileScore($recency, true);
        $fScore = $this->quintileScore($frequency, false);
        $mScore = $this->quintileScore($monetary, false);

        $scores = [];
        $segments = [];
        foreach ($customers as $c) {
            $r = $rScore[$c->id];
            $f = $fScore[$c->id];
            $m = $mScore[$c->id];
            $segment = $this->segment($r, $f, $m);
            $scores[$c->id] = ['customer' => $c, 'r' => $r, 'f' => $f, 'm' => $m, 'segment' => $segment];
            $segments[$segment] = ($segments[$segment] ?? 0) + 1;
        }

        return ['scores' => $scores, 'segments' => $segments];
    }

    /** Score each id 1..5 by quintile. $invert=true means low raw value → high score. */
    private function quintileScore(array $values, bool $invert): array
    {
        $sorted = $values;
        asort($sorted);
        $ids = array_keys($sorted);
        $n = count($ids);
        $out = [];
        foreach ($ids as $i => $id) {
            $q = (int) floor($i / max(1, $n) * 5) + 1; // 1..5 ascending
            $q = min(5, $q);
            $out[$id] = $invert ? (6 - $q) : $q;
        }

        return $out;
    }

    private function segment(int $r, int $f, int $m): string
    {
        if ($r >= 4 && $f >= 4) {
            return 'champions';
        }
        if ($r >= 3 && ($f + $m) >= 6) {
            return 'loyal';
        }
        if ($r >= 4 && $f <= 2) {
            return 'new';
        }
        if ($r <= 2 && $f >= 3) {
            return 'at_risk';
        }
        if ($r <= 2) {
            return 'lapsed';
        }

        return 'others';
    }
}
