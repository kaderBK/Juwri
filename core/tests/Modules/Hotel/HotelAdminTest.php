<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\KitchenStation;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Models\ServiceRequest;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Juwri\Modules\Hotel\Services\Hotel\HotelService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * FEATURES-RUN-4 T1 — H3 rooms admin, H5 service requests, H9 guest info hub.
 * Covers CRUD scoping, bulk create, department routing, status flow, info
 * save/read and hotel_mode gating (404 when off).
 */
class HotelAdminTest extends TestCase
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

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function store(bool $hotel = true): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1', 'hotel' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($hotel) {
            StoreSettings::set($store->id, 'hotel_mode', '1');
        }

        return [$owner, $store];
    }

    private function room(Store $store, int $no = 101, string $type = 'room'): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => $no, 'key' => Str::random(10), 'area_type' => $type,
        ]);
    }

    // ------------------------------------------------------------------ H3

    public function test_rooms_index_lists_only_rooms_and_zones(): void
    {
        [$owner, $store] = $this->store();
        $this->room($store, 101, 'room');
        $this->room($store, 5, 'table'); // ordinary dining table — excluded

        $this->actingAs($owner)->get(route('hotel.rooms'))
            ->assertOk()->assertSee('#101')->assertDontSeeHtml('<strong>#5</strong>');
    }

    public function test_create_room(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->post(route('hotel.rooms.store'), [
            'table_no' => 204, 'area_type' => 'room', 'label' => 'Suite 204', 'floor_label' => 'Floor 2',
        ])->assertRedirect();

        $this->assertDatabaseHas('store_tables', [
            'store_id' => $store->id, 'table_no' => 204, 'area_type' => 'room', 'label' => 'Suite 204',
        ]);
    }

    /**
     * Regression: the room-store controller must persist the room-type assignment
     * posted by the form. It previously dropped `room_type_id`, so every UI-created
     * room was typeless and availableForType() returned 0 — online booking read
     * "sold out" for a fully-configured hotel. Guards single + bulk create.
     */
    public function test_create_room_persists_room_type_so_it_is_bookable(): void
    {
        [$owner, $store] = $this->store();
        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Deluxe', 'base_rate' => 450]);

        $this->actingAs($owner)->post(route('hotel.rooms.store'), [
            'table_no' => 301, 'area_type' => 'room', 'room_type_id' => $type->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('store_tables', [
            'store_id' => $store->id, 'table_no' => 301, 'room_type_id' => $type->id,
        ]);

        // The room is now countable for its type — the booking engine sees 1 free.
        $this->assertSame(1, BookingService::availableForType(
            (int) $store->id, (int) $type->id, '2026-09-10', '2026-09-12'
        ));

        // Bulk create carries the type too.
        $this->actingAs($owner)->post(route('hotel.rooms.bulk'), [
            'from' => 310, 'to' => 312, 'area_type' => 'room', 'room_type_id' => $type->id,
        ])->assertRedirect();
        $this->assertSame(3, StoreTable::where('store_id', $store->id)
            ->where('room_type_id', $type->id)->where('table_no', '>=', 310)->count());

        // A foreign type id is rejected (not persisted) — never trust the posted id.
        $this->seedStores($store->id + 99); // P1-04 5b: the foreign store must exist
        $foreignType = RoomType::create(['store_id' => $store->id + 99, 'name' => 'X', 'base_rate' => 1]);
        $this->actingAs($owner)->post(route('hotel.rooms.store'), [
            'table_no' => 320, 'area_type' => 'room', 'room_type_id' => $foreignType->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('store_tables', [
            'store_id' => $store->id, 'table_no' => 320, 'room_type_id' => null,
        ]);
    }

    public function test_bulk_create_rooms_skips_existing(): void
    {
        [$owner, $store] = $this->store();
        $this->room($store, 103, 'room'); // already exists

        $this->actingAs($owner)->post(route('hotel.rooms.bulk'), [
            'from' => 101, 'to' => 105, 'area_type' => 'room', 'floor_label' => 'Floor 1',
        ])->assertRedirect();

        // 101,102,104,105 created (103 skipped) → 5 total rows.
        $this->assertSame(5, StoreTable::where('store_id', $store->id)->where('area_type', 'room')->count());
    }

    public function test_delete_room_scoped_to_restaurant(): void
    {
        [$owner, $store] = $this->store();
        [, $other] = $this->store();
        $foreign = $this->room($other, 900);

        $this->actingAs($owner)->post(route('hotel.rooms.delete'), ['id' => $foreign->id])
            ->assertNotFound();
        $this->assertDatabaseHas('store_tables', ['id' => $foreign->id]);
    }

    public function test_rooms_404_when_hotel_mode_off(): void
    {
        [$owner] = $this->store(false);
        $this->actingAs($owner)->get(route('hotel.rooms'))->assertNotFound();
    }

    // ------------------------------------------------------------------ H5

    public function test_owner_creates_service_request_with_explicit_department(): void
    {
        [$owner, $store] = $this->store();
        $room = $this->room($store, 110);
        $dept = KitchenStation::create(['store_id' => $store->id, 'name' => 'Housekeeping', 'active' => true, 'position' => 1]);

        $this->actingAs($owner)->post(route('hotel.requests.store'), [
            'table_id' => $room->id, 'type' => 'housekeeping', 'note' => 'Towels', 'department_id' => $dept->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('service_requests', [
            'store_id' => $store->id, 'table_id' => $room->id, 'type' => 'housekeeping',
            'department_id' => $dept->id, 'status' => 'open',
        ]);
    }

    public function test_request_routes_by_settings_department_map(): void
    {
        [$owner, $store] = $this->store();
        $dept = KitchenStation::create(['store_id' => $store->id, 'name' => 'Maintenance', 'active' => true, 'position' => 1]);
        StoreOption::updateStoreOption($store->id, 'hotel_settings', json_encode(['departments' => ['maintenance' => $dept->id]]));

        $this->actingAs($owner)->post(route('hotel.requests.store'), [
            'type' => 'maintenance', 'note' => 'Broken lamp',
        ])->assertRedirect();

        $this->assertDatabaseHas('service_requests', [
            'store_id' => $store->id, 'type' => 'maintenance', 'department_id' => $dept->id,
        ]);
    }

    public function test_update_request_status(): void
    {
        [$owner, $store] = $this->store();
        $req = ServiceRequest::create(['store_id' => $store->id, 'type' => 'staff_call', 'status' => 'open']);

        $this->actingAs($owner)->post(route('hotel.requests.status'), ['id' => $req->id, 'status' => 'in_progress'])
            ->assertRedirect();

        $this->assertDatabaseHas('service_requests', ['id' => $req->id, 'status' => 'in_progress']);
        $this->assertSame($owner->id, (int) $req->fresh()->assigned_staff_id);
    }

    public function test_cannot_update_foreign_request(): void
    {
        [$owner] = $this->store();
        [, $other] = $this->store();
        $req = ServiceRequest::create(['store_id' => $other->id, 'type' => 'custom', 'status' => 'open']);

        $this->actingAs($owner)->post(route('hotel.requests.status'), ['id' => $req->id, 'status' => 'done'])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------ H9

    public function test_info_save_and_read_back(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->post(route('hotel.info.save'), [
            'wifi_name' => 'HotelNet', 'wifi_password' => 'secret123',
            'house_rules' => 'No smoking', 'breakfast_hours' => '07:00-10:30',
            'contact_phone' => '+15551234',
        ])->assertRedirect();

        $settings = HotelService::settings($store->id);
        $this->assertSame('HotelNet', $settings['wifi']['name']);
        $this->assertSame('secret123', $settings['wifi']['password']);
        $this->assertSame('No smoking', $settings['house_rules']);
        $this->assertSame('07:00-10:30', $settings['breakfast']['hours']);
    }

    public function test_public_guest_info_page_renders(): void
    {
        [, $store] = $this->store();
        $room = $this->room($store, 305);
        StoreOption::updateStoreOption($store->id, 'hotel_settings', json_encode(['wifi' => ['name' => 'GuestWifi', 'password' => 'pw']]));

        // C5 (lane C): the Wi-Fi card is gated on proof of room presence — a valid room
        // key (or active-stay token). A bare slug now sees a "scan your room QR" hint,
        // not the credentials, so the page must be reached with the room key to show them.
        $this->get(route('hotel.guest.info', $store->slug).'?table='.$room->key)
            ->assertOk()
            ->assertSee('GuestWifi');
    }

    public function test_guest_submits_service_request_from_room_qr(): void
    {
        [, $store] = $this->store();
        $room = $this->room($store, 302);

        $this->post(route('hotel.guest.request', $store->slug), [
            'table' => $room->key, 'type' => 'amenity', 'note' => 'Extra towels',
        ])->assertRedirect();

        $this->assertDatabaseHas('service_requests', [
            'store_id' => $store->id, 'table_id' => $room->id, 'type' => 'amenity', 'status' => 'open',
        ]);
    }

    public function test_guest_info_404_when_hotel_off(): void
    {
        [, $store] = $this->store(false);
        $this->get(route('hotel.guest.info', $store->slug))->assertNotFound();
    }
}
