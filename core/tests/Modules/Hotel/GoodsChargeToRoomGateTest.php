<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * J52b review round 5 / J57: the goods checkout (POST {slug}/checkout, payment_method=charge_to_room) uses the same
 * gate as the room-service order: room by its QR key → verified room token (required by default) → active stay →
 * full guest name. The old fallbacks (a guessable room number, a name alone, "the only checked-in stay") are gone;
 * anything else is refused before an order exists.
 */
class GoodsChargeToRoomGateTest extends TestCase
{
    use RefreshDatabase;

    private const GUEST = 'Jane Doe';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Mail::fake();
        Notification::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{Store, Model, Model} store, room, active stay */
    private function hotelShop(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'settings' => ['menu_limit' => 999, 'hotel' => '1', 'allow_ordering' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'shop-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Shop',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png',
            'address' => '1 St', 'phone' => '1', 'store_type' => 'retail',
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');
        $room = $store->tables()->create(['table_no' => 101, 'key' => 'rk-'.Str::random(10), 'seats' => 2, 'area_type' => 'room']);
        $stay = HotelFolioService::checkIn($store->id, $room, self::GUEST, '555-1');
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Robe', 'price' => 15, 'active' => 1]);
        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), ['quantity' => 1]);

        return [$store, $room, $stay];
    }

    /** @param array<string, mixed> $extra */
    private function place(Store $store, array $extra): TestResponse
    {
        return $this->postJson(route('storefront.checkout.place', ['slug' => $store->slug]), $extra + [
            'type' => 'ship', 'address' => '1 St', 'customer_name' => self::GUEST, 'phone_number' => '5551234',
            'payment_method' => 'charge_to_room',
        ]);
    }

    private function scanned(Model $room): static
    {
        return $this->withSession(['hotel_room_token' => app(RoomCharge::class)->mintToken($room)]);
    }

    private function assertRefused(TestResponse $response, Store $store, Model $stay): void
    {
        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'a refused charge-to-room places no order');
        $this->assertSame(0.0, round(HotelFolioService::balance($stay->fresh()), 2));
    }

    public function test_room_key_plus_verified_token_plus_full_name_charges_the_room(): void
    {
        [$store, $room, $stay] = $this->hotelShop();

        $this->scanned($room)->place($store, ['room' => $room->key, 'charge_room_name' => self::GUEST])
            ->assertOk()->assertJson(['success' => true, 'charged_to_room' => true]);

        $this->assertSame('charge_to_room', Order::where('store_id', $store->id)->sole()->payment_method);
        $this->assertSame(15.0, round(HotelFolioService::balance($stay->fresh()), 2));
    }

    public function test_no_scanned_token_is_refused(): void
    {
        [$store, $room, $stay] = $this->hotelShop();

        $this->assertRefused($this->place($store, ['room' => $room->key, 'charge_room_name' => self::GUEST]), $store, $stay);
    }

    public function test_a_token_for_another_room_is_refused(): void
    {
        [$store, $room, $stay] = $this->hotelShop();
        $other = $store->tables()->create(['table_no' => 102, 'key' => 'rk-'.Str::random(10), 'seats' => 2, 'area_type' => 'room']);

        $this->assertRefused($this->scanned($other)->place($store, ['room' => $room->key, 'charge_room_name' => self::GUEST]), $store, $stay);
    }

    public function test_the_room_number_fallback_is_gone(): void
    {
        [$store, $room, $stay] = $this->hotelShop();

        $this->assertRefused($this->scanned($room)->place($store, ['charge_room' => '101', 'charge_room_name' => self::GUEST]), $store, $stay);
    }

    public function test_the_name_only_and_single_stay_fallbacks_are_gone(): void
    {
        [$store, $room, $stay] = $this->hotelShop();

        // No room key at all: one checked-in stay whose name matches used to be charged.
        $this->assertRefused($this->scanned($room)->place($store, ['charge_room_name' => self::GUEST]), $store, $stay);
    }

    public function test_a_wrong_or_first_name_only_is_refused(): void
    {
        [$store, $room, $stay] = $this->hotelShop();

        $this->assertRefused($this->scanned($room)->place($store, ['room' => $room->key, 'charge_room_name' => 'Someone Else']), $store, $stay);
        $this->assertRefused($this->scanned($room)->place($store, ['room' => $room->key, 'charge_room_name' => 'Jane']), $store, $stay);
    }

    public function test_a_numeric_room_id_instead_of_the_key_is_refused(): void
    {
        [$store, $room, $stay] = $this->hotelShop();

        $this->assertRefused($this->scanned($room)->place($store, ['room' => (string) $room->getKey(), 'charge_room_name' => self::GUEST]), $store, $stay);
    }
}
