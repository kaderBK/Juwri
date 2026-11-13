<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C5 — the guest info hub. Two audit findings: it was unreachable from any guest surface
 * (now linked from the guest layer's services sheet), and it served the Wi-Fi password
 * to anyone with the public store slug. The password is now gated on proof of room
 * presence (a scanned room key or an active-stay token); house rules / breakfast /
 * reception stay public.
 */
class HotelGuestInfoTest extends TestCase
{
    use RefreshDatabase;

    private const WIFI_PASS = 'SwordfishPass';

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
        StoreSettings::set($store->id, 'hotel_settings', json_encode([
            'wifi' => ['name' => 'HotelNet', 'password' => self::WIFI_PASS],
            'house_rules' => 'No smoking indoors',
            'breakfast' => ['hours' => '07:00 - 10:30', 'cutoff' => '22:00'],
        ]));

        return $store;
    }

    private function room(Store $store): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => 204, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Room 204',
        ]);
    }

    public function test_wifi_password_shown_with_a_valid_room_key(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);

        $this->get(route('hotel.guest.info', $store->slug).'?table='.$room->key)
            ->assertOk()
            ->assertSee(self::WIFI_PASS)
            ->assertSee('No smoking indoors'); // public content also present
    }

    public function test_wifi_password_hidden_for_a_bare_slug(): void
    {
        $store = $this->hotelStore();
        $this->room($store);

        $this->get(route('hotel.guest.info', $store->slug))
            ->assertOk()
            ->assertDontSee(self::WIFI_PASS)
            ->assertSee('Scan the QR code in your room') // the gated hint
            ->assertSee('No smoking indoors'); // house rules stay public
    }

    public function test_wifi_password_shown_with_an_active_stay_token(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');

        $this->get(route('hotel.guest.info', $store->slug).'?token='.$stay->token)
            ->assertOk()
            ->assertSee(self::WIFI_PASS);
    }

    public function test_wifi_password_hidden_with_a_wrong_token(): void
    {
        $store = $this->hotelStore();
        $this->room($store);

        $this->get(route('hotel.guest.info', $store->slug).'?token='.Str::random(48))
            ->assertOk()
            ->assertDontSee(self::WIFI_PASS)
            ->assertSee('Scan the QR code in your room');
    }

    public function test_a_checked_out_stay_token_does_not_reveal_wifi(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $stay->update(['status' => 'checked_out', 'checked_out_at' => now()]);

        $this->get(route('hotel.guest.info', $store->slug).'?token='.$stay->token)
            ->assertOk()
            ->assertDontSee(self::WIFI_PASS);
    }

    public function test_page_is_noindex_and_offers_a_menu_link_when_a_room_resolved(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);

        $this->get(route('hotel.guest.info', $store->slug).'?table='.$room->key)->assertOk()->assertSeeHtml('noindex')->assertSeeHtml('?table='.$room->key); // reciprocal "View menu / order" link
    }
}
