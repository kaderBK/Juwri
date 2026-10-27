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
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C3 — the guest-facing folio ("my bill"). checkIn() mints HotelStay.token, previously
 * consumed by nothing; now a public read-only page renders the live statement from it.
 * Possession of the 48-char token is the capability (no auth); it is valid only while
 * the stay is active or within 7 days of check-out, and every miss is a plain 404.
 */
class HotelGuestFolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    }

    private function hotelStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'hotel' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');

        return $store;
    }

    private function room(Store $store): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => 204, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Room 204',
        ]);
    }

    private function chargeOrder(Store $store, StoreTable $room, $stay, float $price): Order
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'pending',
            'table_number' => $room->table_no, 'service_location' => 'Room '.$room->table_no,
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);
        HotelFolioService::chargeOrder($order->fresh(), $stay);

        return $order;
    }

    public function test_happy_path_renders_the_live_statement(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $order = $this->chargeOrder($store, $room, $stay, 42.00);

        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]))
            ->assertOk()
            ->assertSee('Room 204')
            ->assertSee('Sam Carter')
            ->assertSee('Balance due')
            ->assertSee('42.00')
            ->assertSee('#'.$order->id); // the charge line carries the order ref
    }

    public function test_unknown_token_is_404(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);
        HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');

        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => Str::random(48)]))
            ->assertNotFound();
    }

    public function test_a_short_or_garbage_token_is_404(): void
    {
        $store = $this->hotelStore();

        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => 'abc']))
            ->assertNotFound();
    }

    public function test_checked_out_within_grace_still_renders(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $stay->update(['status' => 'checked_out', 'checked_out_at' => now()->subDays(2)]);

        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]))
            ->assertOk()
            ->assertSee('Checked out');
    }

    public function test_checked_out_beyond_grace_is_404(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $stay->update(['status' => 'checked_out', 'checked_out_at' => now()->subDays(8)]);

        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]))
            ->assertNotFound();
    }

    public function test_a_cancelled_stay_is_404(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $stay->update(['status' => 'cancelled']);

        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]))
            ->assertNotFound();
    }

    public function test_hotel_mode_off_is_404(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        StoreOption::updateStoreOption($store->id, 'hotel_mode', '0');

        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]))
            ->assertNotFound();
    }

    public function test_rtl_locale_renders_the_page_in_rtl(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('ar');

        $store = $this->hotelStore();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');

        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]))->assertOk()->assertSeeHtml('dir="rtl"')->assertSeeHtml('noindex');
    }
}
