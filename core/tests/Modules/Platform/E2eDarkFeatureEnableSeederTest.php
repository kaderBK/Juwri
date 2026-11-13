<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Modules\Storefront\Database\Seeders\E2eDarkFeatureEnableSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2ePlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * Regression (e2e on the Phase 1 schema): the dark-feature seeder used the legacy
 * dev-DB ids (store 3440 / owner 10145), so on any fresh database biolink and
 * catering were never entitled and the owner pages returned 403. It must resolve
 * crave-demo and its owner by slug.
 */
class E2eDarkFeatureEnableSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_entitles_the_crave_demo_owner_on_a_fresh_database(): void
    {
        $this->seed(E2ePlatformSeeder::class);
        $store = DB::table('stores')->where('slug', 'crave-demo')->first(['id', 'user_id']);
        $this->assertNotNull($store);
        $this->assertNotSame(3440, (int) $store->id, 'guard: a fresh DB must not reuse the legacy dev id');

        $this->seed(E2eDarkFeatureEnableSeeder::class);

        foreach (['biolink', 'catering', 'bio_pro'] as $key) {
            $this->assertTrue(
                DB::table('entitlement_overrides')->where('user_id', $store->user_id)->where('feature_key', $key)->exists(),
                "owner of crave-demo is entitled to {$key}"
            );
            $this->assertTrue(PlatformSettings::bool("admin_allow_{$key}"), "platform switch for {$key} is on");
        }
        $this->assertGreaterThan(0, DB::table('bio_blocks')->where('store_id', $store->id)->count());
        // The Fusion multi-store bag endpoint is 404 until this platform switch is on (c-fusion multicart).
        $this->assertTrue(PlatformSettings::bool('admin_allow_multivendor_cart'));

        // Idempotent.
        $this->seed(E2eDarkFeatureEnableSeeder::class);
        $this->assertSame(3, DB::table('entitlement_overrides')->where('user_id', $store->user_id)->whereIn('feature_key', ['biolink', 'catering', 'bio_pro'])->count());
    }
}
