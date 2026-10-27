<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Settings\PlatformSettings;

/**
 * What the lane-A e2e journeys (tests/e2e/journeys/uncovered/a-*.js, b-*.js ...) need from a development database that
 * E2ePlatformV2Seeder cannot give them without its crave-demo fixtures:
 *   - the test-only `stub` payment gateway catalog row (inert unless the serving app is local/testing with E2E_STUBS armed);
 *   - the default-OFF platform switches of the features the journeys exercise (accounting, kiosk, driver app).
 * Idempotent. Never run against production.
 */
class E2eLaneAJourneysSeeder extends Seeder
{
    /** Default-OFF platform switches the journeys need on (the owner entitlement is granted in the dashboard by the journey). */
    public const PLATFORM_FEATURES = ['accounting_gl', 'kiosk', 'driver_app'];

    public function run(): void
    {
        DB::table('payment_gateways')->updateOrInsert(
            ['payment_folder' => E2ePlatformV2Seeder::STUB_GATEWAY_FOLDER],
            ['payment_install' => '1', 'payment_title' => 'E2E Stub Gateway']
        );

        foreach (self::PLATFORM_FEATURES as $key) {
            PlatformSettings::set('admin_allow_'.$key, '1');
        }
    }
}
