<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelBookingNight;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Tests\TestCase;

/**
 * HPMS A6 — check-in handoff + nightly folio posting: full handoff, nightly
 * lines sum == quote, early-checkout reversal, double check-in idempotent,
 * legacy walk-in unaffected.
 */
class HotelPmsCheckinTest extends TestCase
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

    private function typeWithRoom(float $base = 100, string $label = 'Suite 1'): array
    {
        $type = RoomType::create(['store_id' => $this->rid, 'name' => 'Standard', 'base_rate' => $base]);
        $room = StoreTable::create([
            'store_id' => $this->rid, 'table_no' => (string) rand(100, 999), 'label' => $label,
            'key' => Str::random(10), 'area_type' => 'room', 'room_type_id' => $type->id, 'hk_status' => 'clean',
        ]);

        return [$type, $room];
    }

    private function booking(RoomType $type, StoreTable $room, string $in, string $out): HotelBooking
    {
        return BookingService::create($this->rid, [
            'guest_name' => 'Sam Carter', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => $in, 'check_out' => $out,
        ], HotelBooking::STATUS_CONFIRMED);
    }

    public function test_full_handoff_opens_stay_and_links_booking(): void
    {
        [$type, $room] = $this->typeWithRoom(100);
        $b = $this->booking($type, $room, '2026-09-01', '2026-09-04'); // 3 nights = 300

        $stay = BookingService::checkIn($b);

        $b->refresh();
        $this->assertSame('checked_in', $b->status);
        $this->assertSame((int) $stay->id, (int) $b->hotel_stay_id);
        $this->assertSame('checked_in', $stay->status);
        $this->assertEqualsWithDelta(300.0, HotelFolioService::balance($stay), 0.001);
    }

    public function test_nightly_lines_sum_equals_quote_with_dated_refs(): void
    {
        [$type, $room] = $this->typeWithRoom(120);
        $b = $this->booking($type, $room, '2026-09-01', '2026-09-03'); // 2 nights = 240
        $stay = BookingService::checkIn($b);

        // One dated folio line per night, ref hotel_folio:night:{stay}:{date}:folio.
        foreach (['2026-09-01', '2026-09-02'] as $d) {
            $this->assertTrue(
                LedgerEntry::where('ref', 'hotel_folio:night:'.$stay->id.':'.$d.':folio')->exists(),
                "night line $d"
            );
        }
        $statement = HotelFolioService::statement($stay);
        $this->assertEqualsWithDelta(240.0, $statement['total_charges'], 0.001);
    }

    public function test_double_check_in_is_idempotent(): void
    {
        [$type, $room] = $this->typeWithRoom(100);
        $b = $this->booking($type, $room, '2026-09-01', '2026-09-03');

        $stay1 = BookingService::checkIn($b);
        $stay2 = BookingService::checkIn($b);

        $this->assertSame((int) $stay1->id, (int) $stay2->id);
        // Nightly charges posted exactly once (idempotent ref).
        $this->assertEqualsWithDelta(200.0, HotelFolioService::balance($stay1), 0.001);
    }

    public function test_early_checkout_reverses_unstayed_nights(): void
    {
        [$type, $room] = $this->typeWithRoom(100);
        $b = $this->booking($type, $room, '2026-09-01', '2026-09-05'); // 4 nights = 400
        $stay = BookingService::checkIn($b);
        $this->assertEqualsWithDelta(400.0, HotelFolioService::balance($stay), 0.001);

        // Settle the 2 stayed nights, then check out on 2026-09-03 (leaving early).
        HotelFolioService::settle($stay, 200.0, 'cash');
        $closed = BookingService::checkOut($b, '2026-09-03');

        $this->assertSame('checked_out', $closed->status);
        $this->assertEqualsWithDelta(0.0, HotelFolioService::balance($closed), 0.001);
        $b->refresh();
        $this->assertSame('checked_out', $b->status);

        // The two unstayed nights were reversed.
        foreach (['2026-09-03', '2026-09-04'] as $d) {
            $this->assertTrue(LedgerEntry::where('ref', 'hotel_folio:night:'.$stay->id.':'.$d.':rev:folio')->exists(), "reversed $d");
        }
        // The stayed nights were NOT reversed.
        $this->assertFalse(LedgerEntry::where('ref', 'hotel_folio:night:'.$stay->id.':2026-09-01:rev:folio')->exists());
    }

    public function test_extend_posts_additional_nights(): void
    {
        [$type, $room] = $this->typeWithRoom(100);
        $b = $this->booking($type, $room, '2026-09-01', '2026-09-03'); // 2 nights
        $stay = BookingService::checkIn($b);
        $this->assertEqualsWithDelta(200.0, HotelFolioService::balance($stay), 0.001);

        BookingService::extend($b, '2026-09-05'); // now 4 nights
        $this->assertEqualsWithDelta(400.0, HotelFolioService::balance($stay->fresh()), 0.001);
        $this->assertSame(4, HotelBookingNight::where('booking_id', $b->id)->count());
    }

    public function test_legacy_walkin_checkout_unaffected_by_booking_hook(): void
    {
        // A walk-in stay has no linked booking → no reversal, no booking sync.
        [$type, $room] = $this->typeWithRoom(100);
        $stay = HotelFolioService::checkIn($this->rid, $room, 'Walk In');
        $closed = HotelFolioService::checkOut($stay);
        $this->assertSame('checked_out', $closed->status);
    }
}
