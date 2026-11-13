<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Database\Seeders;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Menu\Models\Menu;

/**
 * Idempotent demo hotel that unblocks the D6 hotel end-to-end browser gate:
 * a plan that grants the `hotel` entitlement, an owner on it, a store with
 * `hotel_mode` on, rooms (store_tables with area_type='room' — decision
 * D5, rooms are NOT a separate table), an in-room dining menu and one
 * checked-in guest stay with a stable token.
 *
 * ADDITIVE ONLY: every row keys on its own natural key via updateOrCreate /
 * firstOrCreate, so `db:seed --class=HotelDemoSeeder` can run any number of
 * times without duplicating rows. It never touches an existing plan, user,
 * store or post_option — in particular the shared "Pro Packege" plan.
 *
 * Run:  php artisan db:seed --class=HotelDemoSeeder
 */
class HotelDemoSeeder extends Seeder
{
    private const PLAN_NAME = 'ZZ Hotel Demo Throwaway Plan (seeder)';

    private const SLUG = 'hotel-demo';

    private const OWNER_EMAIL = 'hotel-owner@demo.test';

    private const OWNER_PASS = 'password';

    // Stable per-stay guest link token so a browser session can deep-link in.
    // Alphanumeric: the guest-folio route (hotel.guest.folio) constrains {token}
    // to [A-Za-z0-9]+ (mirroring the Str::random(48) production mint), so a
    // hyphenated token 404s before the controller ever runs.
    private const STAY_TOKEN = 'hoteldemostaytoken0001';

    private const GUEST_PHONE = '966500000401';

    /** Room numbers created as store_tables rows with area_type='room'. */
    private const ROOMS = [101, 102];

    public function run(): void
    {
        // 1. Throwaway plan carrying the `hotel` entitlement.
        //    plans.settings is cast to object and holds the legacy entitlement
        //    blob. Entitlements::has() does `(string) $value === '1'`
        //    (app/Services/Entitlements.php:57) — so 'hotel' MUST be the STRING
        //    '1'; boolean true / 'yes' / 'on' all silently fail the gate.
        //    Shape mirrors tests/Modules/Hotel/HotelAdminTest.php::store().
        //    P1-13: plans are Billing (ee) — the plan is written by Billing's DemoThrowawayPlanSeeder when it is present;
        //    in Community the owner runs on the edition plan (every feature on), so there is no plan to write.
        if ($planSeeder = ModuleSeeders::find('DemoThrowawayPlanSeeder')) {
            $this->call($planSeeder, true, [
                'name' => self::PLAN_NAME,
                'description' => 'Throwaway plan created by HotelDemoSeeder — do not sell.',
                'settings' => [
                    'restaurant_limit' => 5,
                    'menu_limit' => 999,
                    'table_qr' => '1',
                    'allow_ordering' => '1',
                    'hotel' => '1',
                ],
            ]);
        }
        $planId = app(PlanCatalog::class)->plans()->firstWhere('name', self::PLAN_NAME)?->getKey();

        // 2. Throwaway owner on that plan. users.group_id is the legacy-but-
        //    fully-supported link (User::plan() falls back to Plan::find(group_id)
        //    when there is no live subscription) and is stored as a string.
        $owner = User::updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'username' => 'hoteldemo',
                'name' => 'Hotel Demo Owner',
                'password' => Hash::make(self::OWNER_PASS),
                'user_type' => 'user',
                'group_id' => $planId !== null ? (string) $planId : 'free',
                'status' => 1,
                'country_code' => 'SA',
                'country' => 'Saudi Arabia',
                'email_verified_at' => now(),
            ]
        );

        // 3. The store (posts row) owned by that user.
        $store = Store::updateOrCreate(
            ['slug' => self::SLUG],
            [
                'user_id' => $owner->id,
                'title' => 'Hotel Demo Residence',
                'sub_title' => 'In-room dining demo property',
                'description' => 'Demo hotel provisioned by HotelDemoSeeder.',
                'color' => '#1b4332',
                'main_image' => 'default.png',
                'cover_image' => 'default.png',
                'address' => 'Corniche Rd, Jeddah',
                'phone' => '966500000400',
                'catalog_visible' => 1,
            ]
        );
        $rid = $store->id;

        // 4. Per-restaurant option flags. hotel_mode is the owner master toggle
        //    HotelService::enabled() reads; the order-type flags must be ON or
        //    the storefront hides the menu and the product CTA entirely (the
        //    theme-pack seeder bug that hid everything).
        foreach ([
            'hotel_mode' => '1',
            'restaurant_on_table_order' => '1',
            'restaurant_takeaway_order' => '1',
            'restaurant_delivery_order' => '1',
        ] as $name => $value) {
            StoreOption::updateStoreOption($rid, $name, $value);
        }

        // 5. Menu content so a guest can actually place a room-service order.
        //    NOTE the documented trap: `active` is a string enum — writing int 1
        //    coerces to '0' and hides the entire menu. Always write '1'.
        $catRoomService = MenuCategory::updateOrCreate(
            ['store_id' => $rid, 'name' => 'Room Service'],
            ['position' => 1, 'active' => '1']
        );
        $catBreakfast = MenuCategory::updateOrCreate(
            ['store_id' => $rid, 'name' => 'Breakfast'],
            ['position' => 2, 'active' => '1']
        );

        $items = [
            ['cat' => $catRoomService, 'name' => 'Club Sandwich',        'price' => 42, 'calories' => 640, 'prep' => 18],
            ['cat' => $catRoomService, 'name' => 'Margherita Pizza',     'price' => 55, 'calories' => 820, 'prep' => 22],
            ['cat' => $catRoomService, 'name' => 'Caesar Salad',         'price' => 34, 'calories' => 310, 'prep' => 10],
            ['cat' => $catBreakfast,   'name' => 'Continental Breakfast', 'price' => 60, 'calories' => 700, 'prep' => 15],
            ['cat' => $catBreakfast,   'name' => 'Fresh Orange Juice',   'price' => 18, 'calories' => 110, 'prep' => 4],
        ];

        foreach ($items as $pos => $it) {
            Menu::updateOrCreate(
                ['store_id' => $rid, 'name' => $it['name']],
                [
                    'category_id' => $it['cat']->id,
                    'description' => $it['name'].' — demo in-room dining item.',
                    'price' => $it['price'],
                    'calories' => $it['calories'],
                    'prep_minutes' => $it['prep'],
                    'active' => '1',
                    'position' => $pos,
                ]
            );
        }

        // 6. Rooms — decision D5: a room IS a store_tables row with
        //    area_type='room'. firstOrCreate (not updateOrCreate) so the random
        //    `key` is generated once and stays stable across reruns; the QR URLs
        //    printed below must not change between runs.
        $rooms = [];
        foreach (self::ROOMS as $i => $no) {
            $rooms[] = StoreTable::firstOrCreate(
                ['store_id' => $rid, 'table_no' => $no, 'area_type' => 'room'],
                [
                    'key' => Str::random(10),
                    // No label: the guest greeting renders ___('Room').' '.$label,
                    // falling back to table_no when label is empty. Seeding
                    // 'Room 101' here would render "Room Room 101".
                    'label' => null,
                    'floor_label' => 'Floor 1',
                    'seats' => 2,
                ]
            );
        }

        // 7. One checked-in guest stay on the first room, with a stable token.
        //    Keyed on the stay IDENTITY (restaurant + room), not on the token: the
        //    theme showcase seeder writes a stay for the same room, and a second
        //    row would split the folio - the room's charge lands on the newest
        //    checked-in stay while the guest's own link names this one.
        $stay = HotelStayRows::onePerRoom($rid, (int) $rooms[0]->id, [
            'token' => self::STAY_TOKEN,
            'guest_name' => 'Demo Guest',
            'guest_phone' => self::GUEST_PHONE,
            'checked_in_at' => now()->subHours(3),
        ]);

        // 8. Everything a browser session needs, in one place.
        $this->command?->info('HotelDemoSeeder: store "'.self::SLUG.'" (post '.$rid.') ready.');
        $this->command?->info('  Plan: '.($planId !== null ? self::PLAN_NAME.' (id '.$planId.', hotel => "1")' : 'edition plan (Community)'));
        $this->command?->info('  Owner login: '.self::OWNER_EMAIL.' / '.self::OWNER_PASS);
        $this->command?->info('  Storefront: '.route('publicView', $store->slug));
        foreach ($rooms as $room) {
            $this->command?->info('  Room #'.$room->table_no.' QR: '.store_public_qr_url($store, $room->key));
        }
        $this->command?->info('  Guest stay token: '.$stay->token.' (room #'.$rooms[0]->table_no.', '.$stay->status.')');
    }
}
