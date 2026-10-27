<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Modules\Storefront\Database\Seeders\E2eCraveFixture;
use Juwri\Modules\Storefront\Database\Seeders\E2eDispatchDemoSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2eKdsStationDemoSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2ePlatformSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2ePlatformV2Seeder;
use Juwri\Modules\Storefront\Database\Seeders\E2ePosModifierDemoSeeder;
use Juwri\Modules\Pos\Database\Seeders\PosV2DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * Regression (e2e on the Phase 1 schema): the platform-v2 fixture seeders pinned the ids of one
 * legacy dev database (crave-demo = store 3440 / owner 10145 / items 64944 + 64945, Pizza Paradise
 * = 2695), so on a freshly seeded database they silently did nothing (or wrote nothing useful) and
 * the dispatch / KDS / POS journeys had no fixtures. They must resolve crave-demo by slug and its
 * items by name.
 */
class E2eCraveFixtureSeedersTest extends TestCase
{
    use RefreshDatabase;

    private function craveStore(): object
    {
        $this->seed(E2ePlatformSeeder::class);
        $store = E2eCraveFixture::store();
        $this->assertNotNull($store);
        $this->assertNotSame(3440, $store->id, 'guard: a fresh DB must not reuse the legacy dev id');

        return $store;
    }

    public function test_kds_stations_route_the_named_items_on_a_fresh_database(): void
    {
        $store = $this->craveStore();
        $grill = E2eCraveFixture::itemId($store->id, 'Crispy Onion Rings');
        $cold = E2eCraveFixture::itemId($store->id, 'Loaded Fries');
        $this->assertNotNull($grill, 'the showcase menu carries the grill item');
        $this->assertNotNull($cold, 'the showcase menu carries the cold item');

        $this->seed(E2eKdsStationDemoSeeder::class);

        $grillStation = DB::table('kitchen_stations')->where('store_id', $store->id)->where('name', 'E2E Grill')->value('id');
        $coldStation = DB::table('kitchen_stations')->where('store_id', $store->id)->where('name', 'E2E Cold')->value('id');
        $this->assertNotNull($grillStation);
        $this->assertNotNull($coldStation);
        $this->assertSame((int) $grillStation, (int) DB::table('items')->where('id', $grill)->value('station_id'));
        $this->assertSame((int) $coldStation, (int) DB::table('items')->where('id', $cold)->value('station_id'));
        $this->assertTrue(DB::table('entitlement_overrides')->where('user_id', $store->user_id)->where('feature_key', 'kds')->exists());
    }

    public function test_dispatch_and_pos_modifier_fixtures_land_on_the_crave_store(): void
    {
        $store = $this->craveStore();

        // The v2 seeder creates tenant-B first (the foreign driver's store) and chains the fixture seeders.
        $this->seed(E2ePlatformV2Seeder::class);

        $this->assertTrue(DB::table('drivers')->where('store_id', $store->id)->where('email', E2eDispatchDemoSeeder::DRIVER_EMAIL)->exists());
        // ...and a driver on another tenant, for the tenant-fence probe.
        $this->assertTrue(DB::table('drivers')->where('store_id', '<>', $store->id)->where('email', E2eDispatchDemoSeeder::FOREIGN_DRIVER_EMAIL)->exists());
        $this->assertSame($store->id, (int) DB::table('orders')->where('track_token', E2eDispatchDemoSeeder::ORDER_TRACK)->value('store_id'));
        // The assign route is behind feature:driver_app (default-off switch + plan gate).
        $this->assertTrue(PlatformSettings::bool('admin_allow_driver_app'));
        $this->assertTrue(DB::table('entitlement_overrides')->where('user_id', $store->user_id)->where('feature_key', 'driver_app')->exists());
        $this->assertTrue(DB::table('modifier_groups')->where('store_id', $store->id)->where('title', E2ePosModifierDemoSeeder::GROUP_TITLE)->exists());
        $this->assertTrue(DB::table('entitlement_overrides')->where('user_id', $store->user_id)->where('feature_key', 'pos_enabled')->exists());
    }

    public function test_pos_v2_demo_seeder_resolves_its_store_by_slug(): void
    {
        $store = $this->craveStore();
        putenv('E2E_POS_STORE_SLUG=crave-demo');
        try {
            $this->seed(PosV2DemoSeeder::class);
        } finally {
            putenv('E2E_POS_STORE_SLUG');
        }

        $this->assertTrue(DB::table('store_tables')->where('store_id', $store->id)->where('table_no', 6)->exists());
        $this->assertTrue(DB::table('orders')->where('store_id', $store->id)->where('client_uuid', 'posv2-demo-held')->exists());
    }
}
