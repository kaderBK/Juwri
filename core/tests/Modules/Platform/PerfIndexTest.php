<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The analytics + dispatch composite indexes (2026_08_10) must be created by the
 * migration — the hot menu_item_events and delivery-board filters depend on them.
 */
class PerfIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_and_dispatch_composite_indexes_exist(): void
    {
        $indexNames = fn (string $table) => collect(Schema::getIndexes($table))->pluck('name')->all();

        $this->assertContains('mie_rid_type_created_idx', $indexNames('menu_item_events'),
            'menu_item_events(store_id, type, created_at) composite index is missing');
        $this->assertContains('orders_rid_type_delivery_idx', $indexNames('orders'),
            'orders(store_id, type, delivery_status) composite index is missing');
    }
}
