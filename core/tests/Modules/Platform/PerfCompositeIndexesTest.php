<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P3 — the three hot filter/sort combinations carry covering indexes.
 */
class PerfCompositeIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_composite_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasIndex('store_page_views', 'pv_post_date_idx'), 'store_page_views(store_id,date) index missing');
        $this->assertTrue(Schema::hasIndex('orders', 'orders_rest_status_created_idx'), 'orders(store_id,status,created_at) index missing');
        $this->assertTrue(Schema::hasIndex('platform_notifications', 'notif_status_idx'), 'platform_notifications(status) index missing');
    }

    public function test_index_names_fit_mysql_cap_with_prefix(): void
    {
        foreach (['pv_post_date_idx', 'orders_rest_status_created_idx', 'notif_status_idx'] as $name) {
            $this->assertLessThanOrEqual(64, strlen('qrtest_'.$name), "$name too long with prefix");
        }
    }
}
