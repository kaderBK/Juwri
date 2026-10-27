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
use Juwri\Modules\Hotel\Models\HotelEvent;
use Juwri\Modules\Hotel\Models\HotelEventItem;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Juwri\Modules\Hotel\Services\Hotel\EventService;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Tests\TestCase;

/**
 * HPMS A8 — hotel events: standalone (own hotel_event account) vs linked-to-stay
 * billing, settle idempotency, cancel guards.
 */
class HotelEventLedgerTest extends TestCase
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

    private function event(array $attrs = []): HotelEvent
    {
        $e = HotelEvent::create(array_merge([
            'store_id' => $this->rid, 'title' => 'Wedding', 'status' => 'enquiry',
        ], $attrs));
        HotelEventItem::create(['event_id' => $e->id, 'description' => 'Room hire', 'qty' => 1, 'unit_price' => 500]);
        HotelEventItem::create(['event_id' => $e->id, 'description' => 'Catering', 'qty' => 20, 'unit_price' => 15]);

        return $e; // total = 500 + 300 = 800
    }

    private function checkedInStay(): HotelStay
    {
        $type = RoomType::create(['store_id' => $this->rid, 'name' => 'Std', 'base_rate' => 100]);
        $room = StoreTable::create([
            'store_id' => $this->rid, 'table_no' => (string) rand(100, 999),
            'key' => Str::random(10), 'area_type' => 'room', 'room_type_id' => $type->id, 'hk_status' => 'clean',
        ]);
        $b = BookingService::create($this->rid, [
            'guest_name' => 'Sam', 'room_type_id' => $type->id, 'room_id' => $room->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-02',
        ], HotelBooking::STATUS_CONFIRMED);

        return BookingService::checkIn($b); // 1 night = 100 on the folio
    }

    public function test_standalone_event_bills_its_own_account(): void
    {
        $e = $this->event();
        EventService::confirm($e);

        $e->refresh();
        $this->assertSame('confirmed', $e->status);
        $this->assertNotNull($e->folio_account_id);
        $acct = LedgerAccount::find($e->folio_account_id);
        $this->assertSame('hotel_event', $acct->kind);
        $this->assertEqualsWithDelta(800.0, HotelFolioService::eventBalance($e), 0.001);
    }

    public function test_linked_event_bills_the_stay_folio(): void
    {
        $stay = $this->checkedInStay();
        $this->assertEqualsWithDelta(100.0, HotelFolioService::balance($stay), 0.001);

        $e = $this->event(['hotel_stay_id' => $stay->id]);
        EventService::confirm($e);

        // The stay folio now carries the room night (100) + the event items (800).
        $this->assertEqualsWithDelta(900.0, HotelFolioService::balance($stay), 0.001);
        // The event's own contribution is 800.
        $this->assertEqualsWithDelta(800.0, HotelFolioService::eventBalance($e), 0.001);
        $this->assertNull($e->fresh()->folio_account_id); // no separate account when linked
    }

    public function test_confirm_is_idempotent_no_double_billing(): void
    {
        $e = $this->event();
        EventService::confirm($e);
        HotelFolioService::postEventItems($e); // replay
        $this->assertEqualsWithDelta(800.0, HotelFolioService::eventBalance($e), 0.001);
    }

    public function test_settle_is_idempotent(): void
    {
        $e = $this->event();
        EventService::confirm($e);
        $key = (string) Str::uuid();

        HotelFolioService::settleEvent($e, 300, 'cash', $key);
        HotelFolioService::settleEvent($e, 300, 'cash', $key); // replay same key

        $this->assertEqualsWithDelta(500.0, HotelFolioService::eventBalance($e), 0.001); // 800 − 300 once
    }

    public function test_standalone_cancel_blocked_until_zero_balance(): void
    {
        $e = $this->event();
        EventService::confirm($e); // balance 800

        try {
            EventService::cancel($e);
            $this->fail('expected a balance guard');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('Settle', $ex->getMessage());
        }

        HotelFolioService::settleEvent($e, 800, 'cash');
        $cancelled = EventService::cancel($e);
        $this->assertSame('cancelled', $cancelled->status);
    }

    public function test_linked_cancel_reverses_charges_off_the_stay_folio(): void
    {
        $stay = $this->checkedInStay(); // folio 100
        $e = $this->event(['hotel_stay_id' => $stay->id]);
        EventService::confirm($e); // folio 900

        EventService::cancel($e);
        $this->assertSame('cancelled', $e->fresh()->status);
        // Event charges reversed → back to the room night only.
        $this->assertEqualsWithDelta(100.0, HotelFolioService::balance($stay), 0.001);
    }

    public function test_event_statement_mirrors_folio_shape(): void
    {
        $e = $this->event();
        EventService::confirm($e);
        HotelFolioService::settleEvent($e, 200, 'cash');

        $st = HotelFolioService::eventStatement($e);
        $this->assertSame($e->id, $st['event_id']);
        $this->assertArrayHasKey('charges', $st);
        $this->assertArrayHasKey('payments', $st);
        $this->assertEqualsWithDelta(800.0, $st['total_charges'], 0.001);
        $this->assertEqualsWithDelta(200.0, $st['total_payments'], 0.001);
        $this->assertEqualsWithDelta(600.0, $st['balance'], 0.001);
    }
}
