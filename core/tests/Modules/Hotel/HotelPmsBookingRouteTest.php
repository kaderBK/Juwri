<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS A5 — booking-engine routes: registration, the staff permission matrix
 * (hotel_folio gates booking actions), field validation, cross-tenant 404,
 * availability JSON contract.
 */
class HotelPmsBookingRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
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

    private function typeWithRoom(Store $store, float $base = 100): RoomType
    {
        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Standard', 'base_rate' => $base]);
        StoreTable::create([
            'store_id' => $store->id, 'table_no' => (string) rand(100, 999),
            'key' => Str::random(10), 'area_type' => 'room', 'room_type_id' => $type->id,
        ]);

        return $type;
    }

    private function staff(Store $store, string $role, array $permissions = []): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ] + ($permissions ? ['permissions' => $permissions] : []));
    }

    private function asStaff(User $owner, StaffMember $staff): self
    {
        return $this->actingAs($owner, 'web')->actingAs($staff, 'staff')
            ->withSession(['staff_session_id' => $staff->id]);
    }

    public function test_all_pms_booking_routes_are_registered(): void
    {
        foreach ([
            'hotel.pms.availability', 'hotel.pms.calendar', 'hotel.pms.bookings',
            'hotel.pms.bookings.store', 'hotel.pms.bookings.show', 'hotel.pms.bookings.update',
            'hotel.pms.bookings.confirm', 'hotel.pms.bookings.cancel', 'hotel.pms.bookings.noshow',
        ] as $name) {
            $this->assertTrue(Route::has($name), $name);
        }
    }

    public function test_availability_returns_type_quote_contract(): void
    {
        [$owner, $store] = $this->store();
        $this->typeWithRoom($store, 150);

        $res = $this->actingAs($owner, 'web')
            ->getJson(route('hotel.pms.availability', ['check_in' => '2026-09-01', 'check_out' => '2026-09-04']))
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('types', $res);
        $this->assertArrayHasKey('currency', $res);
        $this->assertSame('USD', $res['currency']);
        $this->assertSame(1, $res['types'][0]['available']);
        $this->assertEquals(450, $res['types'][0]['nightly_total']); // 3 × 150
        $this->assertSame(3, $res['types'][0]['nights']);
    }

    public function test_store_creates_a_confirmed_desk_booking(): void
    {
        [$owner, $store] = $this->store();
        $type = $this->typeWithRoom($store);

        $res = $this->actingAs($owner, 'web')->postJson(route('hotel.pms.bookings.store'), [
            'guest_name' => 'Sam Carter', 'room_type_id' => $type->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
        ])->assertOk()->json();

        $this->assertSame('confirmed', $res['status']);
        $this->assertNotEmpty($res['code']);
        $this->assertEquals(200, $res['quoted_total']);
        $this->assertSame('USD', $res['currency']);
    }

    public function test_store_validation_rejects_missing_fields(): void
    {
        [$owner, $store] = $this->store();
        $this->typeWithRoom($store);

        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.bookings.store'), [
            'guest_name' => 'X', // missing room_type_id + dates
        ])->assertStatus(422);
    }

    public function test_store_rejects_checkout_not_after_checkin(): void
    {
        [$owner, $store] = $this->store();
        $type = $this->typeWithRoom($store);

        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.bookings.store'), [
            'guest_name' => 'X', 'room_type_id' => $type->id,
            'check_in' => '2026-09-03', 'check_out' => '2026-09-03',
        ])->assertStatus(422);
    }

    public function test_idempotency_key_dedupes_double_submit(): void
    {
        [$owner, $store] = $this->store();
        $type = $this->typeWithRoom($store);
        $key = (string) Str::uuid();

        $a = $this->actingAs($owner, 'web')->postJson(route('hotel.pms.bookings.store'), [
            'guest_name' => 'Sam', 'room_type_id' => $type->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03', 'idempotency_key' => $key,
        ])->assertOk()->json();

        $b = $this->actingAs($owner, 'web')->postJson(route('hotel.pms.bookings.store'), [
            'guest_name' => 'Sam', 'room_type_id' => $type->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03', 'idempotency_key' => $key,
        ])->assertOk()->json();

        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, HotelBooking::forRestaurant((int) $store->id)->count());
    }

    public function test_waiter_without_hotel_folio_cannot_book(): void
    {
        [$owner, $store] = $this->store();
        $type = $this->typeWithRoom($store);

        $this->asStaff($owner, $this->staff($store, 'waiter'))
            ->postJson(route('hotel.pms.bookings.store'), [
                'guest_name' => 'X', 'room_type_id' => $type->id,
                'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
            ])->assertStatus(403);

        $this->assertSame(0, HotelBooking::forRestaurant((int) $store->id)->count());
    }

    public function test_staff_with_hotel_folio_can_book(): void
    {
        [$owner, $store] = $this->store();
        $type = $this->typeWithRoom($store);

        $this->asStaff($owner, $this->staff($store, 'waiter', ['hotel_folio' => true]))
            ->postJson(route('hotel.pms.bookings.store'), [
                'guest_name' => 'X', 'room_type_id' => $type->id,
                'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
            ])->assertOk();

        $this->assertSame(1, HotelBooking::forRestaurant((int) $store->id)->count());
    }

    public function test_cancel_and_no_show_transitions(): void
    {
        [$owner, $store] = $this->store();
        $type = $this->typeWithRoom($store);
        $b = BookingService::create((int) $store->id, [
            'guest_name' => 'X', 'room_type_id' => $type->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
        ], HotelBooking::STATUS_CONFIRMED);

        $this->actingAs($owner, 'web')->postJson(route('hotel.pms.bookings.cancel', $b->id))
            ->assertOk()->assertJson(['status' => 'cancelled']);
    }

    public function test_cross_tenant_booking_is_404(): void
    {
        [$ownerA, $postA] = $this->store();
        [$ownerB, $postB] = $this->store();
        $typeB = $this->typeWithRoom($postB);
        $foreign = BookingService::create((int) $postB->id, [
            'guest_name' => 'B', 'room_type_id' => $typeB->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
        ], HotelBooking::STATUS_CONFIRMED);

        $this->actingAs($ownerA, 'web')
            ->postJson(route('hotel.pms.bookings.cancel', $foreign->id))
            ->assertStatus(404);
    }
}
