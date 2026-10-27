<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use Juwri\Modules\Channels\Models\HsCatalogJob;
use Juwri\Modules\Channels\Models\HsEventLog;
use Juwri\Modules\Channels\Services\HungerStation\FakeHungerStationClient;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationClientFactory;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HungerStation P0 — scaffolding boots: schema present, fixtures load via the fake
 * client, factory resolves, event log + catalog-job models work. No live HS calls.
 */
class HungerStationScaffoldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1); // P1-04 5b: fixture store ids must be real stores
    }

    use RefreshDatabase;

    public function test_migration_added_mapping_columns_and_tables(): void
    {
        $this->assertTrue(Schema::hasColumn('branches', 'hs_vendor_id'));
        $this->assertTrue(Schema::hasColumn('branches', 'hs_chain_id'));
        $this->assertTrue(Schema::hasColumn('items', 'hs_sku'));
        $this->assertTrue(Schema::hasColumn('orders', 'hs_order_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'hs_picking_mode'));
        $this->assertTrue(Schema::hasTable('hungerstation_catalog_jobs'));
        $this->assertTrue(Schema::hasTable('hungerstation_event_logs'));
    }

    public function test_fake_client_returns_fixture_order(): void
    {
        $client = new FakeHungerStationClient();
        $order = $client->fetchOrder('CHAIN_TEST_1', 'b3f1c2a4-0000-4a1b-9c2d-000000000001');

        $this->assertSame('HS-4821', $order['order_code']);
        $this->assertSame('RECEIVED', $order['status']);
        $this->assertCount(2, $order['items']);
        $this->assertSame(73.00, $order['payment']['total']);
    }

    public function test_factory_resolves_and_honours_container_binding(): void
    {
        // default → fake
        $this->assertInstanceOf(FakeHungerStationClient::class, HungerStationClientFactory::make());

        // container binding overrides
        $scripted = new FakeHungerStationClient();
        $scripted->overrides['order'] = ['order_code' => 'HS-9999', 'status' => 'RECEIVED', 'items' => [], 'payment' => []];
        $this->app->bind(HungerStationClientInterface::class, fn () => $scripted);

        $order = HungerStationClientFactory::make()->fetchOrder('c', 'o');
        $this->assertSame('HS-9999', $order['order_code']);
    }

    public function test_event_log_and_catalog_job_persist(): void
    {
        HsEventLog::record('in', 'order.received', ['hs_order_id' => 'X1', 'status_code' => 200]);
        $this->assertDatabaseHas('hungerstation_event_logs', ['hs_order_id' => 'X1', 'direction' => 'in']);

        HsCatalogJob::create(['store_id' => 1, 'kind' => 'export', 'status' => 'QUEUED', 'payload' => ['x' => 1]]);
        $this->assertDatabaseHas('hungerstation_catalog_jobs', ['kind' => 'export', 'status' => 'QUEUED']);
    }
}
