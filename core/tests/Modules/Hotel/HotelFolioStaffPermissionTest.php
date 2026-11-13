<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-H1 — the hotel guest-folio money endpoints must run the staff permission gate.
 *
 * Unlike HotelFolioAdminTest (which disables EnsureStaffDashboardPermission), this
 * suite keeps that middleware ON — disabling it is exactly why the existing suite
 * missed that any staff role could settle or write off a folio.
 */
class HotelFolioStaffPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        // NOTE: EnsureStaffDashboardPermission is deliberately NOT disabled — it is the gate under test.
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1', 'hotel' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');

        return [$owner, $store];
    }

    private function room(Store $store, int $no = 101): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => $no, 'key' => Str::random(10), 'area_type' => 'room',
        ]);
    }

    private function order(Store $store, StoreTable $room, float $price): Order
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'pending',
            'table_number' => $room->table_no, 'service_location' => 'Room '.$room->table_no,
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);

        return $order->fresh();
    }

    private function staff(Store $store, string $role, array $permissions = []): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ] + ($permissions ? ['permissions' => $permissions] : []));
    }

    /** A staff session authenticates on BOTH guards (web = owner) + carries staff_session_id. */
    private function asStaff(User $owner, StaffMember $staff): self
    {
        return $this->actingAs($owner, 'web')->actingAs($staff, 'staff')
            ->withSession(['staff_session_id' => $staff->id]);
    }

    public function test_waiter_cannot_settle_a_guest_folio(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($store, $room, 40.00), $stay);

        $this->asStaff($owner, $this->staff($store, 'waiter'))
            ->post(route('hotel.folio.settle', $stay->id), ['amount' => 10, 'method' => 'cash'])
            ->assertStatus(403);

        // The money did not move.
        $this->assertEqualsWithDelta(40.0, HotelFolioService::balance($stay), 0.001);
    }

    public function test_waiter_cannot_write_off_a_folio_at_check_out(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($store, $room, 40.00), $stay);

        $this->asStaff($owner, $this->staff($store, 'waiter'))
            ->post(route('hotel.folio.checkout', $stay->id), ['write_off' => 1])
            ->assertStatus(403);

        $this->assertSame('checked_in', $stay->fresh()->status);
    }

    public function test_manager_can_still_settle_a_folio(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($store, $room, 40.00), $stay);

        $this->asStaff($owner, $this->staff($store, 'manager'))
            ->post(route('hotel.folio.settle', $stay->id), ['amount' => 10, 'method' => 'cash'])
            ->assertRedirect();

        $this->assertEqualsWithDelta(30.0, HotelFolioService::balance($stay), 0.001);
    }

    public function test_staff_with_an_explicit_deny_override_is_blocked(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($store, $room, 40.00), $stay);

        // A manager (holds hotel_folio by role) with an explicit per-staff deny override.
        $this->asStaff($owner, $this->staff($store, 'manager', ['hotel_folio' => false]))
            ->post(route('hotel.folio.settle', $stay->id), ['amount' => 10, 'method' => 'cash'])
            ->assertStatus(403);

        $this->assertEqualsWithDelta(40.0, HotelFolioService::balance($stay), 0.001);
    }

    public function test_owner_is_unaffected(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($store, $room, 40.00), $stay);

        // No staff_session_id → is_staff_dashboard() false → early return in the middleware.
        $this->actingAs($owner)
            ->post(route('hotel.folio.settle', $stay->id), ['amount' => 10, 'method' => 'cash'])
            ->assertRedirect();

        $this->assertEqualsWithDelta(30.0, HotelFolioService::balance($stay), 0.001);
    }

    // ---------------------------------------------------------------- HP-B1
    // The service-request board + rooms/info admin were unreachable by ALL staff
    // (every route unmapped → 403). Two operational keys now open them.
    //
    // Allowed cases exercise POST endpoints (they redirect without rendering the
    // owner-only dashboard layout, which a staff session cannot resolve); denied
    // cases assert 403 on GET (the middleware short-circuits before the controller).

    public function test_waiter_can_work_the_service_request_board(): void
    {
        [$owner, $store] = $this->store();

        // waiter carries hotel_requests by role → the board's write endpoint is reachable.
        $this->asStaff($owner, $this->staff($store, 'waiter'))
            ->post(route('hotel.requests.store'), ['type' => 'staff_call', 'note' => 'Help'])
            ->assertRedirect();

        $this->assertDatabaseHas('service_requests', ['store_id' => $store->id, 'type' => 'staff_call']);
    }

    public function test_waiter_cannot_open_rooms_admin_or_settings(): void
    {
        [$owner, $store] = $this->store();
        $waiter = $this->staff($store, 'waiter');

        $this->asStaff($owner, $waiter)->get(route('hotel.rooms'))->assertStatus(403);
        $this->asStaff($owner, $waiter)->get(route('hotel.info'))->assertStatus(403);
    }

    public function test_manager_can_reach_requests_rooms_and_info(): void
    {
        [$owner, $store] = $this->store();
        $manager = $this->staff($store, 'manager');

        $this->asStaff($owner, $manager)
            ->post(route('hotel.requests.store'), ['type' => 'housekeeping'])
            ->assertRedirect();
        $this->asStaff($owner, $manager)
            ->post(route('hotel.rooms.store'), ['table_no' => 401, 'area_type' => 'room'])
            ->assertRedirect();
        $this->asStaff($owner, $manager)
            ->post(route('hotel.info.save'), ['wifi_name' => 'Net'])
            ->assertRedirect();

        $this->assertDatabaseHas('store_tables', ['store_id' => $store->id, 'table_no' => 401, 'area_type' => 'room']);
    }

    public function test_manager_deny_override_on_hotel_admin_blocks_rooms(): void
    {
        [$owner, $store] = $this->store();

        $this->asStaff($owner, $this->staff($store, 'manager', ['hotel_admin' => false]))
            ->get(route('hotel.rooms'))
            ->assertStatus(403);
    }

    public function test_waiter_allow_override_on_hotel_admin_opens_rooms(): void
    {
        [$owner, $store] = $this->store();

        $this->asStaff($owner, $this->staff($store, 'waiter', ['hotel_admin' => true]))
            ->post(route('hotel.rooms.store'), ['table_no' => 402, 'area_type' => 'room'])
            ->assertRedirect();

        $this->assertDatabaseHas('store_tables', ['store_id' => $store->id, 'table_no' => 402]);
    }

    public function test_waiter_deny_override_on_hotel_requests_blocks_the_board(): void
    {
        [$owner, $store] = $this->store();

        $this->asStaff($owner, $this->staff($store, 'waiter', ['hotel_requests' => false]))
            ->get(route('hotel.requests'))
            ->assertStatus(403);
    }

    public function test_staff_cannot_settle_a_foreign_restaurants_folio(): void
    {
        [$owner, $store] = $this->store();
        [, $other] = $this->store();
        $foreignRoom = $this->room($other, 909);
        $foreignStay = HotelFolioService::checkIn($other->id, $foreignRoom, 'Mallory');
        HotelFolioService::chargeOrder($this->order($other, $foreignRoom, 40.00), $foreignStay);

        // Manager of $store (holds hotel_folio) reaching restaurant B's stay id → 404.
        $this->asStaff($owner, $this->staff($store, 'manager'))
            ->post(route('hotel.folio.settle', $foreignStay->id), ['amount' => 10, 'method' => 'cash'])
            ->assertStatus(404);

        $this->assertEqualsWithDelta(40.0, HotelFolioService::balance($foreignStay), 0.001);
    }
}
