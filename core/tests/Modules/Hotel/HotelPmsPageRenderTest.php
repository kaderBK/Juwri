<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelBookingNight;
use Juwri\Modules\Hotel\Models\RoomType;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS post-merge guard: every hotel PMS dashboard page must render through its
 * REAL controller (not the renderPage() blade-direct harness) with the data the
 * lane-B blade expects. This catches controller->blade payload/variable-name
 * mismatches — the class of bug the Chrome walk surfaced (calendar grid never
 * built; $types vs $roomTypes; rates month grid; housekeeping flat rooms; the
 * bookings/booking-show selectors; the public availability card shape). A GET
 * that 500s or drops the seeded room-type name means a page is broken for real.
 */
class HotelPmsPageRenderTest extends TestCase
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
        $this->shareLayoutGlobals();
    }

    /** @return array{0: User, 1: Store, 2: RoomType, 3: StoreTable} */
    private function hotelStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'hotel' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');
        StoreSettings::set($store->id, 'hotel_online_booking', 'instant');

        $type = RoomType::create([
            'store_id' => $store->id, 'name' => 'Deluxe King', 'code' => 'DLX',
            'base_rate' => 120, 'max_occupancy' => 2, 'active' => 1, 'sort' => 1,
        ]);
        $room = StoreTable::create([
            'store_id' => $store->id, 'key' => 'room-'.Str::lower(Str::random(8)),
            'table_no' => '101', 'label' => 'Room 101', 'area_type' => 'room', 'room_type_id' => $type->id,
        ]);

        return [$owner, $store, $type, $room];
    }

    /** Confirmed booking with night rows so the calendar/booking pages have data. */
    private function seedBooking(Store $store, RoomType $type, StoreTable $room): HotelBooking
    {
        $booking = HotelBooking::create([
            'store_id' => $store->id, 'code' => 'BK'.Str::upper(Str::random(6)), 'token' => Str::random(40),
            'guest_name' => 'Ada Lovelace', 'guest_phone' => '555-2', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => now()->addDays(2)->toDateString(), 'check_out' => now()->addDays(5)->toDateString(),
            'adults' => 2, 'children' => 0, 'status' => 'confirmed', 'quoted_total' => 360, 'currency' => 'USD', 'source' => 'desk',
        ]);
        foreach ([2, 3, 4] as $off) {
            HotelBookingNight::create(['booking_id' => $booking->id, 'room_id' => $room->id, 'night' => now()->addDays($off)->toDateString()]);
        }

        return $booking;
    }

    public function test_bookings_list_renders_the_booking_with_its_room_type(): void
    {
        [$owner, $store, $type, $room] = $this->hotelStore();
        $booking = $this->seedBooking($store, $type, $room);

        $this->actingAs($owner, 'web')->get(route('hotel.pms.bookings'))
            ->assertOk()
            ->assertSee($booking->code)
            ->assertSee('Deluxe King') // roomType relation must resolve (not '—')
            ->assertSee('New booking');
    }

    public function test_booking_detail_renders_type_plan_and_actions(): void
    {
        [$owner, $store, $type, $room] = $this->hotelStore();
        $booking = $this->seedBooking($store, $type, $room);

        $this->actingAs($owner, 'web')->get(route('hotel.pms.bookings.show', $booking->id))
            ->assertOk()
            ->assertSee('Deluxe King')
            ->assertSee('Check in'); // confirmed → lifecycle action present
    }

    public function test_calendar_grid_renders_rooms_and_a_booked_cell(): void
    {
        [$owner, $store, $type, $room] = $this->hotelStore();
        $this->seedBooking($store, $type, $room);

        $this->actingAs($owner, 'web')->get(route('hotel.pms.calendar', ['start' => now()->addDay()->toDateString()]))
            ->assertOk()
            ->assertSee('Deluxe King')   // room-type group row
            ->assertSee('Room 101')      // the room row built by the controller
            ->assertSee('Ada');          // booked-cell guest label from the occupancy map
    }

    public function test_room_types_page_renders_types_and_the_auto_standard_plan(): void
    {
        [$owner, $store] = $this->hotelStore();

        $this->actingAs($owner, 'web')->get(route('hotel.pms.types'))
            ->assertOk()
            ->assertSee('Deluxe King')  // $roomTypes
            ->assertSee('Standard');    // auto-created default rate plan ($ratePlans)
    }

    public function test_rates_month_grid_renders_for_the_first_type(): void
    {
        [$owner, $store] = $this->hotelStore();

        $this->actingAs($owner, 'web')->get(route('hotel.pms.rates'))
            ->assertOk()
            ->assertSee('Deluxe King'); // selected room type in the month grid header
    }

    public function test_housekeeping_board_renders_the_room(): void
    {
        [$owner, $store] = $this->hotelStore();

        $this->actingAs($owner, 'web')->get(route('hotel.pms.housekeeping'))
            ->assertOk()
            ->assertSee('Room 101'); // flat $rooms list reaches the board
    }

    public function test_services_and_events_pages_render(): void
    {
        [$owner, $store] = $this->hotelStore();

        $this->actingAs($owner, 'web')->get(route('hotel.pms.services'))->assertOk();
        $this->actingAs($owner, 'web')->get(route('hotel.pms.events'))->assertOk();
    }

    public function test_public_booking_page_lists_the_room_type_with_availability(): void
    {
        [$owner, $store, $type, $room] = $this->hotelStore();

        // Dates clear of any booking → the type must show as available, named.
        $in = now()->addDays(20)->toDateString();
        $out = now()->addDays(22)->toDateString();
        $this->get(route('hotel.guest.book', $store->slug).'?check_in='.$in.'&check_out='.$out)
            ->assertOk()
            ->assertSee('Deluxe King'); // availableTypes() must build a named card (not generic 'Room')
    }
}
