<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\StoreOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Core\Settings\PlatformSettings;

/**
 * ISS-V-A-001 / ISS-V-A-002 / ISS-V-C-004 — enable the owner-gated public
 * surfaces (biolink, catering, directory) in the E2E TEST DB ONLY so the v2
 * public-route journeys can exercise them. Grants per-owner entitlements +
 * seeds the minimal content each public route needs. Never a prod default:
 * this is a test seeder chained from E2ePlatformV2Seeder.
 */
class E2eDarkFeatureEnableSeeder extends Seeder
{
    /** crave-demo + its owner, resolved by slug (ids differ per database; the legacy dev DB used 3440 / 10145). */
    private int $storeId = 0;

    private int $ownerId = 0;

    public function run(): void
    {
        $store = DB::table('stores')->where('slug', 'crave-demo')->first(['id', 'user_id']);
        if (! $store) {
            return;
        }
        $this->storeId = (int) $store->id;
        $this->ownerId = (int) $store->user_id;

        $this->entitle('biolink');
        $this->entitle('catering');
        $this->entitle('bio_pro');   // pro bio appearance (background image) — b-biolink-appearance
        $this->enableBiolink();
        // Catering gate is entitlement AND the store's own catering_enabled switch.
        StoreOption::updateStoreOption($this->storeId, 'catering_enabled', '1');
        StoreOption::flushOptionMemo();
        $this->enableDirectory();
        // Platform-level only (no per-owner entitlement): the Fusion multi-store bag (POST /order-hub/add) 404s
        // until the multivendor_cart switch is on, so the c-fusion multicart journey saw an empty bag.
        PlatformSettings::set('admin_allow_multivendor_cart', '1');

        $this->command?->info('E2eDarkFeatureEnableSeeder: biolink+catering entitled + bio page seeded; directory enabled with a listing; multivendor_cart switched on.');
    }

    private function entitle(string $key): void
    {
        // These are default-OFF platform switches (FeatureRegistry): the owner entitlement alone still
        // 403s until the superadmin switch is on.
        PlatformSettings::set('admin_allow_'.$key, '1');
        DB::table('entitlement_overrides')->updateOrInsert(
            ['user_id' => $this->ownerId, 'feature_key' => $key],
            ['value' => '1', 'expires_at' => null, 'reason' => 'E2E v2 dark-feature enable', 'source_type' => 'e2e', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** Store bio ON + a few active blocks so /{slug}/bio renders. */
    private function enableBiolink(): void
    {
        $settings = ['enabled' => true, 'display_name' => 'Crave House', 'tagline' => 'Bio E2E'];
        StoreOption::updateStoreOption($this->storeId, 'bio_settings', json_encode($settings));
        StoreOption::flushOptionMemo();

        if (DB::table('bio_blocks')->where('store_id', $this->storeId)->exists()) {
            return;
        }
        $now = now();
        $blocks = [
            ['type' => 'menu', 'label' => 'Our Menu', 'url' => null, 'position' => 0],
            ['type' => 'link', 'label' => 'Order Online', 'url' => 'https://example.test/order', 'position' => 1],
            ['type' => 'social', 'label' => 'Instagram', 'url' => 'https://instagram.com/e2e', 'position' => 2],
        ];
        foreach ($blocks as $b) {
            DB::table('bio_blocks')->insert(TenantFill::row(DB::connection(), 'bio_blocks', array_merge($b, [
                'store_id' => $this->storeId, 'branch_id' => null, 'config' => json_encode([]),
                'active' => 1, 'clicks_cached' => 0, 'created_at' => $now, 'updated_at' => $now,
            ])));
        }
    }

    /** Platform directory ON + a served city + a listed store so /fusion/explore works. */
    private function enableDirectory(): void
    {
        PlatformSettings::set('admin_allow_directory', '1');
        PlatformSettings::set('dir_enabled', '1');

        // A served city id; the store's marketplace listing flags feed the directory.
        $cityId = (int) (DB::table('cities')->value('id') ?? 0);
        if ($cityId) {
            PlatformSettings::set('dir_cities', json_encode([$cityId]));
            DB::table('stores')->where('id', $this->storeId)->update([
                'mk_enabled' => 1,
                'mk_status' => 'approved',
                'mk_city_id' => $cityId,
                'mk_platform_listed' => 1,
                'mk_reviewed_at' => now(),
            ]);
        }
    }
}
