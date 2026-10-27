<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelRoomToken;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * T1 · H8 — guest-facing hotel layer at checkout:
 *   • tray / room-service charge auto-applied to room_service order totals only;
 *   • the charge-to-room tender posts the order to the guest folio (no gateway),
 *     and is rejected when there is no active stay or the guest name mismatches.
 */
class HotelGuestCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    /** @return array{0:Store,1:Menu,2:StoreTable} */
    private function hotel(?string $tray = null, float $price = 20.0): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'hotel' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'hotel_mode', '1');
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);
        if ($tray !== null) {
            StoreOption::updateStoreOption($r->id, 'hotel_tray_charge', $tray);
        }
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd', 'price' => $price, 'image' => 'd.png', 'type' => 'veg', 'active' => '1', 'position' => 1]);
        $room = StoreTable::create([
            'store_id' => $r->id, 'table_no' => (string) rand(100, 999),
            'key' => Str::random(10), 'seats' => 2, 'area_type' => 'room',
        ]);

        return [$r, $menu, $room];
    }

    private function payload(Menu $m, array $extra = []): array
    {
        return array_merge([
            'items' => json_encode([['id' => $m->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Jane', 'ordering-type' => 'room_service', 'pay_via' => 'cod',
        ], $extra);
    }

    public function test_tray_charge_flat_amount_added_to_room_service_total(): void
    {
        [$r, $m, $room] = $this->hotel('5', 20.0);
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'room_service', 'status' => 'pending',
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 1]);

        // 20 item + 5 tray = 25
        $this->assertSame(25.0, round((float) $order->fresh()->total, 2));
        $this->assertSame(5.0, Order::trayCharge('room_service', 20.0, $r->id));
        // Not applied to other order types.
        $this->assertSame(0.0, Order::trayCharge('takeaway', 20.0, $r->id));
    }

    public function test_tray_charge_percent_of_subtotal(): void
    {
        [$r, $m, $room] = $this->hotel('10%', 30.0);
        $this->assertSame(3.0, Order::trayCharge('room_service', 30.0, $r->id));
    }

    public function test_charge_to_room_posts_order_to_folio_and_creates_no_transaction(): void
    {
        [$r, $m, $room] = $this->hotel(null, 20.0);
        $stay = HotelFolioService::checkIn($r->id, $room, 'Jane Doe', '555-1');

        // J57: the scanned room token (required by default) and the FULL guest name.
        $this->withSession(['hotel_room_token' => HotelRoomToken::mint($room)])
            ->post(route('store.submitOrder', $r), $this->payload($m, [
                'name' => 'Jane Doe', 'table' => $room->key, 'tender' => 'charge_to_room',
            ]))->assertOk()->assertJson(['success' => true, 'charged_to_room' => true]);

        $order = Order::where('store_id', $r->id)->latest('id')->first();
        $this->assertSame('charge_to_room', $order->payment_method);
        // Order value landed on the folio.
        $this->assertSame(20.0, round(HotelFolioService::balance($stay->fresh()), 2));
        // No gateway transaction row was created for a room-charged order.
        $this->assertSame(0, Transaction::where('product_id', $order->id)->count());
    }

    public function test_charge_to_room_rejected_without_active_stay(): void
    {
        [$r, $m, $room] = $this->hotel(null, 20.0);
        // No check-in on this room.
        $this->post(route('store.submitOrder', $r), $this->payload($m, [
            'name' => 'Jane', 'table' => $room->key, 'tender' => 'charge_to_room',
        ]))->assertOk()->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $r->id)->count());
    }

    public function test_charge_to_room_rejected_on_guest_name_mismatch(): void
    {
        [$r, $m, $room] = $this->hotel(null, 20.0);
        HotelFolioService::checkIn($r->id, $room, 'Jane Doe', '555-1');

        $this->withSession(['hotel_room_token' => HotelRoomToken::mint($room)])
            ->post(route('store.submitOrder', $r), $this->payload($m, [
                'name' => 'Someone Else', 'table' => $room->key, 'tender' => 'charge_to_room',
            ]))->assertOk()->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $r->id)->count());
    }
}
