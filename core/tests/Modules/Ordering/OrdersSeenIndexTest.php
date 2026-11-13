<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WI-M11 — the composite index the heartbeat + orders-list poll need
 * (store_id, seen, status), with an explicit short name that stays under
 * MySQL's 64-char cap.
 */
class OrdersSeenIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_carries_the_seen_composite_index(): void
    {
        $this->assertTrue(Schema::hasIndex('orders', 'orders_rest_seen_idx'));
    }

    public function test_index_name_is_within_the_mysql_limit(): void
    {
        $this->assertLessThanOrEqual(64, strlen('orders_rest_seen_idx'));
    }
}
