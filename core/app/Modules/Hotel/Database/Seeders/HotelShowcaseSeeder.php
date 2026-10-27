<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Database\Seeders;

use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Hotel\Models\HotelStay;

/**
 * Turns the 17 kept-theme demo stores (showcase account #10008, slugs
 * "{theme}-demo") into hotels so the hotel guest experience — in-room dining
 * order type + charge-to-room folio — is demoable on every theme.
 *
 * The showcase owner already carries the `hotel` entitlement
 * (plan_feature('hotel', owner) === true), so this seeder does NOT touch the shared
 * plan. It only provisions, per store: hotel_mode on, the on-table order flag
 * (in-room dining is an on-table variant — StoreController gates
 * $allow_room_service on $table->area_type==='room'), a couple of rooms
 * (store_tables with area_type='room' — decision D5, rooms are NOT a
 * separate table), and one checked-in guest stay with a stable token so a
 * browser can deep-link into the charge-to-room flow.
 *
 * ADDITIVE + IDEMPOTENT: every row keys on its own natural key via
 * updateOrCreate / firstOrCreate, so it can run any number of times without
 * duplicating rows. Existing menus are reused (room service shows the store's
 * own menu), so no menu content is created here.
 *
 * Run:  php artisan db:seed --class=HotelShowcaseSeeder
 */
class HotelShowcaseSeeder extends Seeder
{
    /** The 17 kept-theme demo slugs. */
    private const SLUGS = [
        'crave-demo', 'matcha-demo', 'glow-demo', 'aurora-demo', 'vivid-demo',
        'feast-demo', 'velvet-demo', 'dough-demo', 'plate-demo', 'lime-demo',
        'picnic-demo', 'orbit-demo', 'velora-demo', 'crumb-demo', 'soiree-demo',
        'scoop-demo', 'ember-demo',
    ];

    /** Room numbers created as store_tables rows with area_type='room'. */
    private const ROOMS = [101, 102];

    public function run(): void
    {
        foreach (self::SLUGS as $slug) {
            $store = Store::where('slug', $slug)->first();
            if (! $store) {
                $this->command?->warn("HotelShowcaseSeeder: {$slug} not found — skipped.");

                continue;
            }
            $rid = $store->id;

            // 1. Per-store flags. hotel_mode is the owner master toggle
            //    HotelService::enabled() reads; on-table ordering must be on or
            //    the room-service (in-room dining) order type never surfaces.
            foreach (['hotel_mode' => '1', 'restaurant_on_table_order' => '1'] as $name => $value) {
                StoreOption::updateStoreOption($rid, $name, $value);
            }

            // 2. Rooms — a room IS a store_tables row with area_type='room'.
            //    firstOrCreate so the random `key` (the QR token) is generated
            //    once and stays stable across reruns.
            $rooms = [];
            foreach (self::ROOMS as $no) {
                $rooms[] = StoreTable::firstOrCreate(
                    ['store_id' => $rid, 'table_no' => $no, 'area_type' => 'room'],
                    [
                        'key' => Str::random(10),
                        'label' => null,
                        'floor_label' => 'Floor 1',
                        'seats' => 2,
                    ]
                );
            }

            // 3. One checked-in guest stay on the first room, stable token per
            //    store for the charge-to-room folio demo. The token must be
            //    ALPHANUMERIC: the guest-folio route (hotel.guest.folio) constrains
            //    {token} to [A-Za-z0-9]+ (mirroring the Str::random(48) production
            //    mint), so a hyphenated token 404s before the controller. Key the
            //    upsert on the stay identity (restaurant + room), not the token, so
            //    reruns rewrite an old hyphenated token in place instead of orphaning
            //    the row behind a new one.
            $token = 'hotelshowcase'.str_replace('-', '', $slug);
            HotelStay::updateOrCreate(
                ['store_id' => $rid, 'table_id' => $rooms[0]->id],
                [
                    'token' => $token,
                    'guest_name' => 'Demo Guest',
                    'guest_phone' => '966500000401',
                    'status' => 'checked_in',
                    'checked_in_at' => now()->subHours(3),
                ]
            );

            $this->command?->info("HotelShowcaseSeeder: {$slug} (post {$rid}) — hotel_mode on, ".count($rooms).' rooms, 1 checked-in stay.');
            foreach ($rooms as $room) {
                $this->command?->info('  Room #'.$room->table_no.' QR: '.store_public_qr_url($store, $room->key));
            }
            $this->command?->info('  Stay token: '.$token);
        }
    }
}
