<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Crm;

use App\Models\Customer;

/**
 * SYSTEMS-RUN C-P1: nightly auto-tags. System tags live in customers.system_tags
 * (distinct from the free-form tags string) so they can be recomputed without
 * clobbering manual tags. Rules: big_spender ≥ threshold, regular ≥ N orders,
 * lapsed > N days since last order, new (recent first order, few orders).
 */
class AutoTagService
{
    public const SYSTEM_TAGS = ['big_spender', 'regular', 'lapsed', 'new'];

    public function __construct(
        private float $bigSpender = 1000.0,
        private int $regularOrders = 6,
        private int $lapsedDays = 90,
    ) {}

    /** Recompute system tags for one restaurant. Returns customers touched. */
    public function recompute(int $storeId): int
    {
        $touched = 0;
        Customer::where('store_id', $storeId)->chunkById(200, function ($chunk) use (&$touched) {
            foreach ($chunk as $c) {
                $tags = $this->tagsFor($c);
                $current = (array) ($c->system_tags ?? []);
                sort($tags);
                sort($current);
                if ($tags !== $current) {
                    $c->update(['system_tags' => $tags]);
                }
                $touched++;
            }
        });

        return $touched;
    }

    public function tagsFor(Customer $c): array
    {
        $tags = [];
        if ((float) $c->total_spent >= $this->bigSpender) {
            $tags[] = 'big_spender';
        }
        if ((int) $c->orders_count >= $this->regularOrders) {
            $tags[] = 'regular';
        }
        if ($c->last_order_at && now()->diffInDays($c->last_order_at) > $this->lapsedDays) {
            $tags[] = 'lapsed';
        }
        if ((int) $c->orders_count <= 1 && $c->created_at && now()->diffInDays($c->created_at) <= 30) {
            $tags[] = 'new';
        }

        return $tags;
    }
}
