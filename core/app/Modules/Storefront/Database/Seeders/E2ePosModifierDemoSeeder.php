<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Platform E2E v2 — B10 fixture: a REQUIRED variant/modifier group on a crave-demo
 * menu item, so the order-time enforcement (OrderApiService::resolveLines, which
 * POS create goes through) has something to reject. crave-demo's seeded items
 * carry no required modifier, so without this an order can never be "missing" one.
 *
 * Creates a group "E2E Required Size" (required, min_select=1, options Small/Large)
 * and attaches it to a fixed crave-demo item via the item_modifier_group pivot (P1-06)
 * (required=1). Idempotent (keys on restaurant+title, and menu+group for the pivot).
 *
 * Run:  php artisan db:seed --class=E2ePosModifierDemoSeeder --force
 */
class E2ePosModifierDemoSeeder extends Seeder
{
    public const GROUP_TITLE = 'E2E Required Size';

    public function run(): void
    {
        $prefix = DB::getTablePrefix();

        $store = E2eCraveFixture::store();   // crave-demo by slug (ids differ per database)
        if (! $store) {
            $this->command?->warn('E2ePosModifierDemoSeeder: store crave-demo missing — run E2ePlatformSeeder first.');

            return;
        }
        $storeId = $store->id;

        // A fixed active item on the store.
        $item = DB::table('items')->where('store_id', $storeId)->where('active', '1')
            ->whereNull('deleted_at')->orderBy('id')->first();
        if (! $item) {
            $this->command?->warn('E2ePosModifierDemoSeeder: no active menu item on store #'.$storeId);

            return;
        }

        // P1-06: the group is a modifier_groups row (kind variant) with one modifier per option.
        $group = MenuVariantOption::updateOrCreate(
            ['store_id' => $storeId, 'title' => self::GROUP_TITLE],
            [
                'is_global' => false,
                'menu_id' => $item->id,
                'options' => ['Small', 'Large'],
                'option_prices' => [0, 5],
                'position' => 0,
                'active' => true,
                'required' => true,
                'min_select' => 1,
                'max_select' => 1,
            ]
        );

        // Attach to the item via the pivot (this is what resolved_variant_options reads).
        Menu::query()->findOrFail($item->id)->attachedVariantOptions()->syncWithoutDetaching([
            $group->id => ['position' => 0, 'required' => true, 'min_select' => 1, 'max_select' => 1, 'active' => true],
        ]);

        // POS is plan-gated (plan_feature('pos_enabled', owner)); the showcase owner's
        // plan does not grant it. Grant it to THIS owner only via a per-user
        // EntitlementOverride (value=1) so the register is reachable — a per-tenant
        // entitlement, not an app-wide/plan-wide change. Idempotent.
        $ownerId = $store->user_id;
        if ($ownerId) {
            DB::table('entitlement_overrides')->updateOrInsert(
                ['user_id' => $ownerId, 'feature_key' => 'pos_enabled'],
                ['value' => '1', 'expires_at' => null, 'reason' => 'E2E B10 POS enablement', 'source_type' => 'e2e', 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $this->command?->info(sprintf(
            'E2ePosModifierDemoSeeder: required group #%d "%s" attached to item #%d (%s) on store #%d; pos_enabled granted to owner #%d.',
            $group->id, self::GROUP_TITLE, $item->id, $item->name, $storeId, $ownerId,
        ));
    }
}
