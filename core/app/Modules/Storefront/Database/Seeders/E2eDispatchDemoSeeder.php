<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\Driver;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Settings\PlatformSettings;

/**
 * Platform E2E v2 — B9 dispatch fixtures. crave-demo has
 * delivery-type orders but they are all terminal (delivered/cancelled) with a
 * NULL delivery_status, and it owns NO drivers — so the owner dispatch surface
 * (assign a driver, walk the delivery state machine, tenant-fence a foreign
 * driver) has nothing to act on. This adds, idempotently:
 *
 *   1. One active driver that BELONGS to crave-demo (the only driver the assign
 *      endpoint will accept for a crave-demo order).
 *   2. One PENDING delivery order on crave-demo (delivery_status='pending', no driver)
 *      — the assignable order the B9 journey resets + drives each run.
 *
 * Idempotent (keys on driver email + order track_token). Run:
 *   php artisan db:seed --class=E2eDispatchDemoSeeder --force
 */
class E2eDispatchDemoSeeder extends Seeder
{
    public const DRIVER_EMAIL = 'e2e-dispatch-driver@example.test';

    public const FOREIGN_DRIVER_EMAIL = 'e2e-foreign-driver@example.test';

    public const ORDER_TRACK = 'e2eDispatchOrderTRACKtoken00001';

    public function run(): void
    {
        $fixture = E2eCraveFixture::store();   // crave-demo by slug (ids differ per database)
        $store = $fixture ? Store::find($fixture->id) : null;
        if (! $store) {
            $this->command?->warn('E2eDispatchDemoSeeder: store crave-demo missing — run E2ePlatformSeeder first.');

            return;
        }
        $storeId = (int) $store->id;

        // The assign / map / report routes sit behind `feature:driver_app` (a default-OFF platform switch, plan-gated):
        // switch it on and entitle the crave-demo owner, or every dispatch POST is a 403 (the legacy dev DB had both).
        foreach (['driver_app', 'courier_dispatch'] as $key) {
            PlatformSettings::set('admin_allow_'.$key, '1');
            DB::table('entitlement_overrides')->updateOrInsert(
                ['user_id' => $store->user_id, 'feature_key' => $key],
                ['value' => '1', 'expires_at' => null, 'reason' => 'E2E B9 dispatch enablement', 'source_type' => 'e2e', 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $driver = Driver::updateOrCreate(
            ['store_id' => $storeId, 'email' => self::DRIVER_EMAIL],
            [
                'owner_id' => $store->user_id,
                'name' => 'E2E Dispatch Driver',
                'phone' => '+966500009901',
                'token' => 'e2eDispatchDriverToken0000000000000001',
                'active' => 1,
                'is_fleet' => 0,
                'capacity' => 3,
                'active_load' => 0,
                'shift_status' => 'online',
            ]
        );

        // A driver that belongs to ANOTHER tenant (tenantb-demo, seeded by E2ePlatformV2Seeder) — the tenant fence
        // the B9 journey probes by trying to assign it to a crave-demo order. The legacy dev DB had real drivers
        // on other stores; a freshly seeded one has none.
        $other = Store::where('slug', E2ePlatformV2Seeder::TENANT_B_SLUG)->first();
        if ($other) {
            Driver::updateOrCreate(
                ['store_id' => $other->id, 'email' => self::FOREIGN_DRIVER_EMAIL],
                [
                    'owner_id' => $other->user_id,
                    'name' => 'E2E Foreign Driver',
                    'phone' => '+966500009902',
                    'token' => 'e2eForeignDriverToken000000000000002',
                    'active' => 1,
                    'is_fleet' => 0,
                    'capacity' => 3,
                    'active_load' => 0,
                    'shift_status' => 'online',
                ]
            );
        }

        $order = Order::updateOrCreate(
            ['track_token' => self::ORDER_TRACK],
            [
                'store_id' => $storeId,
                'customer_name' => 'E2E Dispatch Customer',
                'phone_number' => '966500009900',
                'address' => '1 Dispatch St, Riyadh',
                'type' => 'delivery',
                'status' => 'pending',
                'delivery_status' => 'pending',
                'driver_id' => null,
                'is_paid' => 1,
            ]
        );

        $this->command?->info(sprintf(
            'E2eDispatchDemoSeeder: driver #%d + pending delivery order #%d on store #%d.',
            $driver->id, $order->id, $storeId,
        ));
    }
}
