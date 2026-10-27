<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Tests\TestCase;

/**
 * FEATURES-RUN-4 T1 / H6 — charge-to-room folio lifecycle on the unified ledger.
 */
class HotelFolioTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(bool $hotel = true): Store
    {
        $owner = User::factory()->create(['status' => 1]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        if ($hotel) {
            StoreSettings::set($r->id, 'hotel_mode', '1');
        }

        return $r;
    }

    private function room(Store $r): StoreTable
    {
        return StoreTable::create([
            'store_id' => $r->id, 'table_no' => (string) rand(100, 999),
            'key' => Str::random(10), 'seats' => 2, 'area_type' => 'room',
        ]);
    }

    /** Build a real room-service order with one $price item so ->total is computed. */
    private function order(Store $r, StoreTable $room, float $price): Order
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'room_service', 'status' => 'pending',
            'table_number' => $room->table_no, 'service_location' => 'Room '.$room->table_no,
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);

        return $order->fresh();
    }

    public function test_check_in_creates_stay_and_opens_folio(): void
    {
        $r = $this->storeFixture();
        $room = $this->room($r);

        $stay = HotelFolioService::checkIn($r->id, $room, 'Sam Carter', '555-1');

        $this->assertSame('checked_in', $stay->status);
        $this->assertNotNull($stay->folio_account_id);
        $folio = LedgerAccount::find($stay->folio_account_id);
        $this->assertSame('hotel_folio', $folio->kind);
        $this->assertSame('hotel_stay', $folio->owner_type);
        $this->assertSame($stay->id, (int) $folio->owner_id);
        $this->assertEqualsWithDelta(0.0, HotelFolioService::balance($stay), 0.001);
    }

    public function test_room_service_charge_to_room_posts_to_folio_and_collects_no_payment(): void
    {
        $r = $this->storeFixture();
        $room = $this->room($r);
        $stay = HotelFolioService::checkIn($r->id, $room, 'Sam');
        $order = $this->order($r, $room, 25.00);

        HotelFolioService::chargeOrder($order, $stay);

        $this->assertEqualsWithDelta(25.0, HotelFolioService::balance($stay), 0.001);
        $this->assertSame('charge_to_room', $order->fresh()->payment_method, 'order is tendered to the folio, not a gateway');
    }

    public function test_charge_is_idempotent_per_order(): void
    {
        $r = $this->storeFixture();
        $room = $this->room($r);
        $stay = HotelFolioService::checkIn($r->id, $room, 'Sam');
        $order = $this->order($r, $room, 25.00);

        $a = HotelFolioService::chargeOrder($order, $stay);
        $b = HotelFolioService::chargeOrder($order, $stay);

        $this->assertSame($a, $b, 'second charge of same order returns the original tx, posts nothing');
        $this->assertEqualsWithDelta(25.0, HotelFolioService::balance($stay), 0.001);
    }

    public function test_multiple_orders_accumulate_on_the_folio(): void
    {
        $r = $this->storeFixture();
        $room = $this->room($r);
        $stay = HotelFolioService::checkIn($r->id, $room, 'Sam');

        HotelFolioService::chargeOrder($this->order($r, $room, 25.00), $stay);
        HotelFolioService::chargeOrder($this->order($r, $room, 15.50), $stay);

        $this->assertEqualsWithDelta(40.5, HotelFolioService::balance($stay), 0.001);
        $st = HotelFolioService::statement($stay);
        $this->assertCount(2, $st['charges']);
        $this->assertEqualsWithDelta(40.5, $st['total_charges'], 0.001);
    }

    public function test_check_out_settles_and_zeroes_the_folio(): void
    {
        $r = $this->storeFixture();
        $room = $this->room($r);
        $stay = HotelFolioService::checkIn($r->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($r, $room, 40.00), $stay);

        // partial then full settlement
        HotelFolioService::settle($stay, 10.00, 'cash');
        $this->assertEqualsWithDelta(30.0, HotelFolioService::balance($stay), 0.001);
        HotelFolioService::settle($stay, 30.00, 'card');
        $this->assertEqualsWithDelta(0.0, HotelFolioService::balance($stay), 0.001);

        $stay = HotelFolioService::checkOut($stay);
        $this->assertSame('checked_out', $stay->status);
        $this->assertNotNull($stay->checked_out_at);
        $this->assertSame('settled', LedgerAccount::find($stay->folio_account_id)->status);
    }

    public function test_check_out_blocks_on_outstanding_balance_unless_written_off(): void
    {
        $r = $this->storeFixture();
        $room = $this->room($r);
        $stay = HotelFolioService::checkIn($r->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($r, $room, 20.00), $stay);

        $this->expectException(\RuntimeException::class);
        HotelFolioService::checkOut($stay);
    }

    public function test_write_off_allows_check_out_and_zeroes_balance(): void
    {
        $r = $this->storeFixture();
        $room = $this->room($r);
        $stay = HotelFolioService::checkIn($r->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($r, $room, 20.00), $stay);

        $stay = HotelFolioService::checkOut($stay, true);

        $this->assertSame('checked_out', $stay->status);
        $this->assertEqualsWithDelta(0.0, HotelFolioService::balance($stay), 0.001);
    }

    public function test_over_settlement_is_rejected(): void
    {
        $r = $this->storeFixture();
        $room = $this->room($r);
        $stay = HotelFolioService::checkIn($r->id, $room, 'Sam');
        HotelFolioService::chargeOrder($this->order($r, $room, 20.00), $stay);

        $this->expectException(\RuntimeException::class);
        HotelFolioService::settle($stay, 25.00, 'cash');
    }

    public function test_loose_guest_name_match(): void
    {
        $r = $this->storeFixture();
        $room = $this->room($r);
        $stay = HotelFolioService::checkIn($r->id, $room, 'Sam Carter');

        $this->assertFalse(HotelFolioService::guestNameMatches($stay, 'sam'), 'J57: a first name alone no longer matches');
        $this->assertTrue(HotelFolioService::guestNameMatches($stay, '  sam   CARTER '));
        $this->assertTrue(HotelFolioService::guestNameMatches($stay, 'Sam Carter'));
        $this->assertFalse(HotelFolioService::guestNameMatches($stay, 'Bob'));
        $this->assertFalse(HotelFolioService::guestNameMatches($stay, ''));
    }

    public function test_non_hotel_restaurant_has_no_active_stay(): void
    {
        $r = $this->storeFixture(false);
        $room = $this->room($r);

        // No stay was created; the charge-to-room guard finds nothing.
        $this->assertNull(HotelFolioService::activeStayForRoom($r->id, $room->id));
        $this->assertSame(0, HotelStay::where('store_id', $r->id)->count());
    }
}
