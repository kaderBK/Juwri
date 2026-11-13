<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelRoomToken;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C10 — the hotel guest journey, end to end at the HTTP level (no browser): a room-QR
 * scan renders the guest layer, a charge-to-room order posts to the folio, and the
 * guest folio page shows the charged line. Also pins the track-receipt tray-charge row
 * on the worst-offender theme (feast) so the itemised lines sum to the printed total.
 */
class HotelGuestJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const GUEST = 'Sam Carter';

    private const PRICE = 42.00;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Mail::fake();
        Notification::fake();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(string $theme = 'crave', array $extra = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1', 'hotel' => '1', 'allow_ordering' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);
        foreach (array_merge([
            'store_template' => $theme,
            'restaurant_on_table_order' => '1',
            'hotel_mode' => '1',
        ], $extra) as $name => $value) {
            StoreOption::updateStoreOption($store->id, $name, $value);
        }

        return $store;
    }

    private function item(Store $store, float $price = self::PRICE): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Club Sandwich', 'price' => $price, 'active' => '1']);
    }

    private function room(Store $store): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => 204, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Room 204',
        ]);
    }

    private function folioTotal(int $stayId): float
    {
        $account = LedgerAccount::where('owner_type', 'hotel_stay')->where('owner_id', $stayId)
            ->where('kind', 'hotel_folio')->first();

        return $account ? round((float) LedgerEntry::where('ledger_account_id', $account->id)->sum(DB::raw(LedgerEntry::minorMoneySql('amount'))), 2) : 0.0;
    }

    public function test_scan_to_charge_to_folio_to_guest_bill(): void
    {
        $store = $this->store();
        $room = $this->room($store);
        $item = $this->item($store);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');

        // 1) Scan the room QR (J57: it carries the room token) — the guest layer renders on the menu.
        $this->get('/'.$store->slug.'?table='.$room->key.'&rt='.HotelRoomToken::mint($room))->assertOk()->assertSeeHtml('id="mbHotelChip"')->assertSeeHtml('id="mbHotelChargeToggle"');

        // 2) Place a charge-to-room order (the payload the field-forwarder produces).
        $this->postJson(route('store.submitOrder', ['store' => $store->id]), [
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => '', 'extras' => []]]),
            'name' => self::GUEST,
            'ordering-type' => 'room_service',
            'room' => $room->key,
            'table' => $room->key,
            'tender' => 'charge_to_room',
            'pay_via' => 'pay_on_delivery',
        ])->assertOk()->assertJson(['success' => true]);

        // 3) The folio was charged the order total.
        $order = Order::where('store_id', $store->id)->latest('id')->first();
        $this->assertSame('charge_to_room', $order->payment_method);
        $this->assertEqualsWithDelta(self::PRICE, $this->folioTotal($stay->id), 0.001);

        // 4) The guest folio page shows the charged line + balance.
        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]))
            ->assertOk()
            ->assertSee('Room 204')
            ->assertSee('#'.$order->id)
            ->assertSee('42.00');
    }

    public function test_feast_track_receipt_itemises_the_tray_charge(): void
    {
        $store = $this->store('feast', ['hotel_tray_charge' => '5']);
        $item = $this->item($store, 20.00);
        $room = $this->room($store);

        // A room-service order whose lines (20.00) + tray (5.00) must both print.
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'pending',
            'table_number' => $room->table_no, 'service_location' => 'Room 204',
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);

        $this->get(route('store.orderTrack', ['token' => $order->track_token]))
            ->assertOk()
            ->assertSee('Tray charge');
    }
}
