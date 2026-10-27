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
 * ISS-V-B-005 — KDS multi-station routing fixtures for the B10 depth journey.
 *
 * Puts crave-demo into advanced (item-level) KDS mode with two active
 * kitchen stations and routes two order-clean menu items to different stations
 * via `menu.station_id` (KdsService::resolveStationForOrderItem's fallback, no
 * branch needed). A placed order then carries per-item station_id, and
 * `kds/poll?station_id=N` returns only that station's line.
 *
 * Idempotent; test-DB only (chained from E2ePlatformV2Seeder).
 */
class E2eKdsStationDemoSeeder extends Seeder
{
    private const GRILL_NAME = 'Crispy Onion Rings';   // no required modifier

    private const COLD_NAME = 'Loaded Fries';             // no required modifier

    /** crave-demo, resolved by slug (ids differ per database; the legacy dev DB used 3440 / 64944 / 64945). */
    private int $storeId = 0;

    public function run(): void
    {
        $store = E2eCraveFixture::store();
        if (! $store) {
            return;
        }
        $this->storeId = $store->id;
        $grillItem = E2eCraveFixture::itemId($this->storeId, self::GRILL_NAME);
        $coldItem = E2eCraveFixture::itemId($this->storeId, self::COLD_NAME);

        // Platform switch (already on in prod copies; ensure for a fresh DB).
        PlatformSettings::set('admin_allow_kds', '1');

        // Owner entitlement (KDS is plan-gated) — per-user override, not app-wide.
        $ownerId = $store->user_id;
        if ($ownerId) {
            DB::table('entitlement_overrides')->updateOrInsert(
                ['user_id' => $ownerId, 'feature_key' => 'kds'],
                ['value' => '1', 'expires_at' => null, 'reason' => 'E2E B10 KDS enablement', 'source_type' => 'e2e', 'updated_at' => now(), 'created_at' => now()]
            );
        }

        // Advanced (item-level/stations) KDS mode for this store.
        StoreOption::updateStoreOption($this->storeId, 'kds_mode', 'advanced');

        // Two active stations.
        $grillId = $this->station('E2E Grill', '#c2410c', 0);
        $coldId = $this->station('E2E Cold', '#0ea5e9', 1);

        // Route two order-clean items to different stations (menu.station_id fallback).
        if ($grillItem) {
            DB::table('items')->where('id', $grillItem)->where('store_id', $this->storeId)->update(['station_id' => $grillId]);
        }
        if ($coldItem) {
            DB::table('items')->where('id', $coldItem)->where('store_id', $this->storeId)->update(['station_id' => $coldId]);
        }

        $this->command?->info(sprintf(
            'E2eKdsStationDemoSeeder: advanced KDS on store #%d; stations Grill #%d / Cold #%d; item #%d→Grill, #%d→Cold; kds entitlement to owner #%d.',
            $this->storeId, $grillId, $coldId, (int) $grillItem, (int) $coldItem, $ownerId,
        ));
    }

    /** Idempotent station upsert keyed by (store_id, name). */
    private function station(string $name, string $color, int $position): int
    {
        $existing = DB::table('kitchen_stations')
            ->where('store_id', $this->storeId)->where('name', $name)->value('id');
        if ($existing) {
            DB::table('kitchen_stations')->where('id', $existing)
                ->update(['active' => 1, 'position' => $position, 'display_color' => $color, 'updated_at' => now()]);

            return (int) $existing;
        }

        return (int) DB::table('kitchen_stations')->insertGetId(TenantFill::row(DB::connection(), 'kitchen_stations', [
            'store_id' => $this->storeId,
            'branch_id' => null,
            'name' => $name,
            'display_color' => $color,
            'target_minutes' => 10,
            'is_expo' => 0,
            'active' => 1,
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
