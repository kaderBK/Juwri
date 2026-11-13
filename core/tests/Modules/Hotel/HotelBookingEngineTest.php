<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelBookingNight;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Juwri\Modules\Hotel\Services\Hotel\RoomUnavailableException;
use Tests\TestCase;

/**
 * HPMS A4 — availability, half-open overlap edges, OOO subtraction, and the
 * hotel_booking_nights UNIQUE double-book race guard.
 */
class HotelBookingEngineTest extends TestCase
{
    use RefreshDatabase;

    private int $rid;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create(['status' => 1]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        $this->rid = (int) $store->id;
    }

    private function type(float $base = 100): RoomType
    {
        return RoomType::create(['store_id' => $this->rid, 'name' => 'Standard', 'base_rate' => $base]);
    }

    private function room(RoomType $type, array $attrs = []): StoreTable
    {
        return StoreTable::create(array_merge([
            'store_id' => $this->rid, 'table_no' => (string) rand(100, 999),
            'key' => Str::random(10), 'seats' => 2, 'area_type' => 'room', 'room_type_id' => $type->id,
        ], $attrs));
    }

    public function test_availability_counts_free_rooms(): void
    {
        $type = $this->type();
        $this->room($type);
        $this->room($type);

        $avail = BookingService::typeAvailability($this->rid, '2026-09-01', '2026-09-03');
        $this->assertSame(2, $avail[$type->id]);
    }

    public function test_confirmed_booking_reduces_availability(): void
    {
        $type = $this->type();
        $this->room($type);
        $this->room($type);

        BookingService::create($this->rid, [
            'guest_name' => 'A', 'room_type_id' => $type->id, 'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
        ], HotelBooking::STATUS_CONFIRMED);

        $avail = BookingService::typeAvailability($this->rid, '2026-09-01', '2026-09-03');
        $this->assertSame(1, $avail[$type->id]);
    }

    public function test_back_to_back_stay_is_legal(): void
    {
        $type = $this->type();
        $room = $this->room($type);

        $a = BookingService::create($this->rid, [
            'guest_name' => 'A', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
        ], HotelBooking::STATUS_CONFIRMED);

        // Checks in the day the first checks out — same room, no overlap.
        $b = BookingService::create($this->rid, [
            'guest_name' => 'B', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => '2026-09-03', 'check_out' => '2026-09-05',
        ], HotelBooking::STATUS_CONFIRMED);

        $this->assertSame('confirmed', $a->status);
        $this->assertSame('confirmed', $b->status);
        $this->assertSame(4, HotelBookingNight::whereIn('booking_id', [$a->id, $b->id])->count());
    }

    public function test_straddling_dates_are_blocked_on_same_room(): void
    {
        $type = $this->type();
        $room = $this->room($type);

        BookingService::create($this->rid, [
            'guest_name' => 'A', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-05',
        ], HotelBooking::STATUS_CONFIRMED);

        $this->expectException(RoomUnavailableException::class);
        BookingService::create($this->rid, [
            'guest_name' => 'B', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => '2026-09-03', 'check_out' => '2026-09-06',
        ], HotelBooking::STATUS_CONFIRMED);
    }

    public function test_double_book_race_is_caught_by_the_unique(): void
    {
        $type = $this->type();
        $room = $this->room($type);

        $a = BookingService::create($this->rid, [
            'guest_name' => 'A', 'room_type_id' => $type->id,
            'check_in' => '2026-09-10', 'check_out' => '2026-09-12',
        ], HotelBooking::STATUS_PENDING);
        $b = BookingService::create($this->rid, [
            'guest_name' => 'B', 'room_type_id' => $type->id,
            'check_in' => '2026-09-10', 'check_out' => '2026-09-12',
        ], HotelBooking::STATUS_PENDING);

        // First assign wins.
        BookingService::assignRoom($a, $room->id);
        $this->assertSame(2, HotelBookingNight::where('booking_id', $a->id)->count());

        // Second assign to the SAME room/nights loses on the UNIQUE(room_id,night).
        try {
            BookingService::assignRoom($b, $room->id);
            $this->fail('expected RoomUnavailableException');
        } catch (RoomUnavailableException $e) {
            // The loser's tx rolled back: no nights leaked onto booking B.
            $this->assertSame(0, HotelBookingNight::where('booking_id', $b->id)->count());
            $this->assertSame(2, HotelBookingNight::where('room_id', $room->id)->count());
        }
    }

    public function test_out_of_order_room_subtracts_from_availability(): void
    {
        $type = $this->type();
        $this->room($type); // free
        $this->room($type, ['oo_status' => 'out_of_order', 'oo_until' => '2026-09-30']);

        $avail = BookingService::typeAvailability($this->rid, '2026-09-01', '2026-09-03');
        $this->assertSame(1, $avail[$type->id]); // 2 rooms − 1 OOO
    }

    public function test_out_of_service_does_not_reduce_availability(): void
    {
        $type = $this->type();
        $this->room($type);
        $this->room($type, ['oo_status' => 'out_of_service']);

        $avail = BookingService::typeAvailability($this->rid, '2026-09-01', '2026-09-03');
        $this->assertSame(2, $avail[$type->id]);
    }

    public function test_cancel_frees_the_nights(): void
    {
        $type = $this->type();
        $room = $this->room($type);
        $a = BookingService::create($this->rid, [
            'guest_name' => 'A', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
        ], HotelBooking::STATUS_CONFIRMED);

        BookingService::cancel($a);
        $this->assertSame('cancelled', $a->status);
        $this->assertSame(0, HotelBookingNight::where('booking_id', $a->id)->count());

        // Room reopens: a new confirmed booking on the same dates succeeds.
        $b = BookingService::create($this->rid, [
            'guest_name' => 'B', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
        ], HotelBooking::STATUS_CONFIRMED);
        $this->assertSame('confirmed', $b->status);
    }

    public function test_modify_frees_and_rewrites_nights(): void
    {
        $type = $this->type();
        $room = $this->room($type);
        $a = BookingService::create($this->rid, [
            'guest_name' => 'A', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
        ], HotelBooking::STATUS_CONFIRMED);
        $this->assertSame(2, HotelBookingNight::where('booking_id', $a->id)->count());

        BookingService::modify($a, ['check_in' => '2026-09-01', 'check_out' => '2026-09-05']); // extend to 4 nights
        $nights = HotelBookingNight::where('booking_id', $a->id)->orderBy('night')->pluck('night')->map(fn ($n) => Carbon::parse($n)->toDateString())->all();
        $this->assertSame(['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'], $nights);
        $this->assertSame(400.0, (float) $a->fresh()->quoted_total); // re-quoted 4 × 100
    }

    public function test_no_show_frees_nights(): void
    {
        $type = $this->type();
        $room = $this->room($type);
        $a = BookingService::create($this->rid, [
            'guest_name' => 'A', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
        ], HotelBooking::STATUS_CONFIRMED);

        BookingService::markNoShow($a);
        $this->assertSame('no_show', $a->status);
        $this->assertSame(0, HotelBookingNight::where('booking_id', $a->id)->count());
    }

    public function test_pending_request_pins_quote_and_currency(): void
    {
        $type = $this->type(150);
        $b = BookingService::create($this->rid, [
            'guest_name' => 'Req', 'room_type_id' => $type->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-04',
        ], HotelBooking::STATUS_PENDING, HotelBooking::SOURCE_REQUEST);

        $this->assertSame('pending', $b->status);
        $this->assertSame('request', $b->source);
        $this->assertSame(450.0, (float) $b->quoted_total);
        $this->assertSame('USD', $b->currency);
        $this->assertNotNull($b->code);
    }
}
