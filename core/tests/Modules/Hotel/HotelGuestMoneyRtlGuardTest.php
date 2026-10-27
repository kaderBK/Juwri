<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Lane D — D5. Money/RTL guard for the three STANDALONE hotel guest pages after they
 * adopt the store's identity at token level (D1–D4). This pins the 2026-08-20/21 SAR +
 * RTL fixes (SPEC §2.5 / constraint #13) against the restyle AND every future one:
 *
 *  - every price is wrapped in `<bdi dir="ltr">` (an LTR-isolated money unit);
 *  - the Saudi Riyal sign is bidi-isolated — the exact `U+2068 U+20C1 U+2069`
 *    (FSI · U+20C1 · PDI) sequence lives INSIDE that bdi;
 *  - a payment carries its minus `U+2212` INSIDE the bdi (leftmost), so RTL cannot
 *    detach the sign to the wrong side;
 *  - the always-on riyal `@font-face` (unicode-range U+20C1) is included, so the
 *    glyph is not a tofu box.
 *
 * Rendered on a SAR store under the `ar` locale, through the controller (HTTP GET),
 * not a blade-direct render (constraint #16).
 */
class HotelGuestMoneyRtlGuardTest extends TestCase
{
    use RefreshDatabase;

    /** The bidi-isolated Saudi Riyal sign: FIRST STRONG ISOLATE · U+20C1 · POP DIRECTIONAL ISOLATE. */
    private const RIYAL_ISOLATE = "\u{2068}\u{20C1}\u{2069}";

    /** Unicode MINUS SIGN — the glyph the folio prepends INSIDE the bdi for a payment. */
    private const MINUS = "\u{2212}";

    private const BDI_OPEN = '<bdi dir="ltr">';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('ar');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    protected function tearDown(): void
    {
        // This class renders under 'ar'; reset so the RTL locale does not leak into
        // later tests (currency_pos_effective() is locale-sensitive).
        app()->setLocale('en');
        parent::tearDown();
    }

    /** A SAR hotel store with an accent set (so the bridge tokens are exercised too). */
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
            'color' => '#FF2F00', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');
        StoreSettings::set($store->id, 'hotel_online_booking', 'request');
        // SAR, sign-first (pos=1) with RTL-auto — the production Saudi configuration.
        StoreSettings::set($store->id, 'currency_code', 'SAR');
        StoreSettings::set($store->id, 'currency', 'SAR');
        StoreSettings::set($store->id, 'currency_pos', '1');
        StoreSettings::set($store->id, 'currency_rtl_auto', '1');

        return $store;
    }

    private function room(Store $store, RoomType $type): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => 204, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'room_type_id' => $type->id, 'label' => 'Room 204',
        ]);
    }

    private function roomType(Store $store): RoomType
    {
        return RoomType::create([
            'store_id' => $store->id, 'name' => 'Deluxe King', 'code' => 'DLX',
            'base_rate' => 450, 'max_occupancy' => 2, 'active' => 1, 'sort' => 1,
        ]);
    }

    /**
     * Pull every `<bdi dir="ltr">…</bdi>` inner string out of a rendered page.
     *
     * @return list<string>
     */
    private function moneyBdis(string $html): array
    {
        preg_match_all('/<bdi dir="ltr">(.*?)<\/bdi>/us', $html, $m);

        return $m[1];
    }

    private function assertHasRiyalMoney(string $html, string $where): void
    {
        $this->assertStringContainsString('dir="rtl"', $html, "$where: page must render RTL under ar");

        $bdis = $this->moneyBdis($html);
        $this->assertNotEmpty($bdis, "$where: at least one <bdi dir=\"ltr\"> money unit must render");

        $withRiyal = array_filter($bdis, fn ($b) => str_contains($b, self::RIYAL_ISOLATE));
        $this->assertNotEmpty(
            $withRiyal,
            "$where: the U+2068 U+20C1 U+2069 (FSI·riyal·PDI) sequence must sit INSIDE a <bdi dir=\"ltr\">"
        );

        // The always-on riyal @font-face include must be present (else the glyph is tofu).
        $this->assertMatchesRegularExpression(
            '/unicode-range:\s*U\+20C1/i',
            $html,
            "$where: the riyal-font \@font-face (unicode-range U+20C1) include must be present"
        );
    }

    public function test_booking_page_money_is_riyal_isolated_and_rtl(): void
    {
        $store = $this->hotelStore();
        $this->roomType($store); // active type → the booking page renders a priced card

        $html = $this->get(route('hotel.guest.book', $store->slug))->assertOk()->getContent();

        $this->assertHasRiyalMoney($html, 'booking');
    }

    public function test_booking_manage_page_money_is_riyal_isolated_and_rtl(): void
    {
        $store = $this->hotelStore();
        $type = $this->roomType($store);

        $booking = HotelBooking::create([
            'store_id' => $store->id, 'room_type_id' => $type->id,
            'code' => strtoupper(Str::random(8)), 'token' => Str::random(48),
            'status' => 'pending', 'check_in' => now()->addDays(3)->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(), 'adults' => 2, 'children' => 0,
            'guest_name' => 'Sam Carter', 'guest_phone' => '555-1', 'quoted_total' => 900,
        ]);

        $html = $this->get(route('hotel.guest.booking', ['slug' => $store->slug, 'token' => $booking->token]))
            ->assertOk()->getContent();

        $this->assertHasRiyalMoney($html, 'booking-manage');
    }

    public function test_guest_folio_money_and_payment_minus_stay_inside_the_bdi_under_ar(): void
    {
        $store = $this->hotelStore();
        $type = $this->roomType($store);
        $room = $this->room($store, $type);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');

        // A room-service charge (folio balance rises) and a partial settlement (a payment).
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 120.00, 'active' => 1]);
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'room_service', 'status' => 'pending',
            'table_number' => $room->table_no, 'service_location' => 'Room '.$room->table_no,
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);
        HotelFolioService::chargeOrder($order->fresh(), $stay);
        HotelFolioService::settle($stay, 50.00, 'cash'); // a payment → the Payments section renders

        $html = $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]))
            ->assertOk()->getContent();

        $this->assertHasRiyalMoney($html, 'guest-folio');

        // The payment carries its minus U+2212 INSIDE a money bdi (leftmost token), so a
        // negative amount reads as one unit and RTL cannot fling the sign to the far side.
        $bdis = $this->moneyBdis($html);
        $payment = array_filter($bdis, fn ($b) => str_contains($b, self::MINUS));
        $this->assertNotEmpty(
            $payment,
            'guest-folio: a payment must carry U+2212 INSIDE its <bdi dir="ltr"> money unit'
        );
        foreach ($payment as $b) {
            // Number-first in RTL (−50.00 ⃁): the minus leads the amount inside the
            // bdi, immediately before the digits, so a negative payment reads as one
            // unit and RTL cannot fling the sign to the far side.
            $this->assertMatchesRegularExpression(
                '/'.self::MINUS.'\d/u',
                $b,
                'guest-folio: the payment minus must sit directly before the number, inside the bdi'
            );
        }

        // Belt-and-braces on the raw markup: no payment minus ever leaks OUTSIDE a bdi
        // (e.g. `−<bdi>` would be the RTL-detaching bug the fix removed).
        $this->assertStringNotContainsString(
            self::MINUS.self::BDI_OPEN,
            $html,
            'guest-folio: a minus must never sit immediately BEFORE <bdi> (it belongs inside)'
        );
    }
}
