<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\StoreOption;
use Illuminate\Database\Seeder;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;

/**
 * e2e fixture (tests/e2e/lib/lane-a.js ensureStore): one more store for the lane owner when the dashboard wizard
 * refused it at the Community store limit (one store of each store type per business) — the journeys that need a
 * second restaurant test other features, not the limit (the limit has its own journey). Runs through `db:seed`, which
 * the edition store/branch limits do not apply to (install-time data). Reads E2E_STORE_OWNER_EMAIL, E2E_STORE_TYPE,
 * E2E_STORE_TITLE, E2E_STORE_SLUG. Idempotent by slug. Never run against production.
 */
class E2eLaneAStoreSeeder extends Seeder
{
    public function run(): void
    {
        $email = self::var('E2E_STORE_OWNER_EMAIL', '');
        $slug = self::var('E2E_STORE_SLUG', '');
        $owner = User::where('email', $email)->first();
        if (! $owner || $slug === '') {
            $this->command?->warn('E2eLaneAStoreSeeder: nothing to do — set E2E_STORE_OWNER_EMAIL (an existing user) and E2E_STORE_SLUG.');

            return;
        }
        if (Store::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            return;
        }
        $title = self::var('E2E_STORE_TITLE', $slug);
        $store = Store::create([
            'user_id' => $owner->id, 'store_type' => self::var('E2E_STORE_TYPE', 'restaurant'), 'slug' => $slug,
            'title' => $title, 'sub_title' => '', 'description' => 'Created by the e2eA journeys.', 'color' => '#10b981',
            'address' => '1 Test Street, Riyadh', 'phone' => '+966500000001', 'main_image' => 'default.png', 'cover_image' => 'default.png',
        ]);

        // what the dashboard wizard switches on for a new store (StoreController::newStoreOptions): without them the storefront
        // takes no orders, and the journeys that use this store (b-accounting, d-kiosk, e-driver) need to order
        $on = ['allow_call_waiter', 'allow_auto_user_info', 'allow_auto_popup', 'open_close_store', 'prevent_order_on_close', 'restaurant_on_table_order', 'store_submit_order_notification'];
        foreach ($on as $name) {
            StoreOption::updateStoreOption($store->id, $name, 1);
        }
    }

    /** A process environment variable (the e2e runner passes them to this one artisan call; not app config). */
    private static function var(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
