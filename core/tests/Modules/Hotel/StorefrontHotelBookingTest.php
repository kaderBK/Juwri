<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\RoomType;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical — the aurelia (hotel) theme's storefront room endpoints
 * (StorefrontHotelController): date-range availability + direct booking, thin
 * wrappers over the hotel BookingService/RoomType engine. These are the routes
 * aurelia's blade POSTs to (hotel.rooms.availability / hotel.rooms.book) — the
 * QR/room-token guest flow (hotel.guest.*) is a different surface.
 */
class StorefrontHotelBookingTest extends TestCase
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

    private function hotelStore(bool $enabled = true): Store
    {
        // HotelService::enabled() = owner hotel_mode toggle AND the plan's `hotel`
        // feature entitlement, so the owner needs a plan that grants it.
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'hotel' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'hotel']);
        if ($enabled) {
            StoreOption::updateStoreOption($store->id, 'hotel_mode', '1');
        }

        return $store;
    }

    private function roomType(Store $store, float $rate = 100): RoomType
    {
        return RoomType::create(['store_id' => $store->id, 'name' => 'Standard', 'base_rate' => $rate, 'active' => true]);
    }

    private function room(Store $store, RoomType $type): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => (string) rand(100, 999),
            'key' => Str::random(10), 'seats' => 2, 'area_type' => 'room', 'room_type_id' => $type->id,
        ]);
    }

    public function test_availability_lists_room_types_with_free_counts_and_range_totals(): void
    {
        $store = $this->hotelStore();
        $type = $this->roomType($store, 150);
        $this->room($store, $type);
        $this->room($store, $type);

        $res = $this->getJson(route('hotel.rooms.availability', ['slug' => $store->slug]).'?check_in=2026-09-01&check_out=2026-09-03');

        $res->assertOk()->assertJson(['enabled' => true, 'nights' => 2]);
        $room = collect($res->json('rooms'))->firstWhere('id', $type->id);
        $this->assertNotNull($room);
        $this->assertSame(2, $room['available']);
        // 150/night × 2 nights = 300.
        $this->assertEqualsWithDelta(300.0, (float) $room['total'], 0.001);
    }

    public function test_availability_is_disabled_for_a_store_with_hotel_mode_off(): void
    {
        $store = $this->hotelStore(enabled: false);

        $res = $this->getJson(route('hotel.rooms.availability', ['slug' => $store->slug]));

        $res->assertOk()->assertJson(['enabled' => false, 'rooms' => []]);
    }

    public function test_book_creates_a_reservation_and_returns_its_code(): void
    {
        $store = $this->hotelStore();
        $type = $this->roomType($store, 120);
        $this->room($store, $type);

        $res = $this->postJson(route('hotel.rooms.book', ['slug' => $store->slug]), [
            'room_type_id' => $type->id,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-03',
            'guest_name' => 'Ada Lovelace',
            'guest_phone' => '5551234',
            'adults' => 2,
        ]);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertNotEmpty($res->json('code'));
        $booking = HotelBooking::where('store_id', $store->id)->first();
        $this->assertNotNull($booking);
        $this->assertSame($res->json('code'), $booking->code);
    }

    public function test_book_rejects_check_out_before_check_in(): void
    {
        $store = $this->hotelStore();
        $type = $this->roomType($store);
        $this->room($store, $type);

        $res = $this->postJson(route('hotel.rooms.book', ['slug' => $store->slug]), [
            'room_type_id' => $type->id,
            'check_in' => '2026-09-05',
            'check_out' => '2026-09-03',
            'guest_name' => 'Sam',
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, HotelBooking::where('store_id', $store->id)->count());
    }

    public function test_book_is_unavailable_for_a_non_hotel_store(): void
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);

        $res = $this->postJson(route('hotel.rooms.book', ['slug' => $store->slug]), [
            'room_type_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-03',
            'guest_name' => 'Sam',
        ]);

        $res->assertStatus(422)->assertJson(['success' => false]);
    }
}
