<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C3 — guest surfaces glue: the storefront "Book a room" CTA (gated on hotel_online_booking)
 * and the guest folio's grouped room-nights / services sections (bucketed by ledger ref
 * prefix without touching HotelFolioService::statement).
 */
class HotelGuestSurfacesTest extends TestCase
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

    private function renderPartial(Store $store): string
    {
        return view('store_templates._partials.hotel-guest', ['store' => $store])->render();
    }

    // -------------------------------------------------------- book-link matrix

    public function test_book_link_hidden_when_booking_off(): void
    {
        $store = $this->hotelStore(); // no hotel_online_booking option => off

        $this->assertStringNotContainsString('Book a room', $this->renderPartial($store));
    }

    public function test_book_link_shows_in_request_mode(): void
    {
        $store = $this->hotelStore();
        StoreSettings::set($store->id, 'hotel_online_booking', 'request');

        $html = $this->renderPartial($store);
        $this->assertStringContainsString('Book a room', $html);
        $this->assertStringContainsString(route('hotel.guest.book', $store->slug), $html);
    }

    public function test_book_link_shows_in_instant_mode(): void
    {
        $store = $this->hotelStore();
        StoreSettings::set($store->id, 'hotel_online_booking', 'instant');

        $this->assertStringContainsString('Book a room', $this->renderPartial($store));
    }

    public function test_book_link_hidden_when_hotel_mode_off(): void
    {
        $store = $this->hotelStore();
        StoreOption::updateStoreOption($store->id, 'hotel_mode', '0');
        StoreSettings::set($store->id, 'hotel_online_booking', 'request');

        $this->assertStringNotContainsString('Book a room', $this->renderPartial($store));
    }

    // -------------------------------------------------------- folio grouping

    private function seedEntry($account, float $amount, string $ref, string $reason, array $meta = []): void
    {
        LedgerEntry::create([
            'ledger_account_id' => $account->id,
            'amount' => $amount,
            'balance_after' => $amount,
            'transaction_id' => Str::random(16),
            'type' => $amount >= 0 ? 'credit' : 'debit',
            'ref' => $ref,
            'reason' => $reason,
            'meta' => $meta,
        ]);
    }

    public function test_folio_groups_nights_and_services(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $acct = HotelFolioService::folioAccountFor($stay);

        // Night lines (ledger folio leg carries the :folio suffix — prefix match still holds).
        $this->seedEntry($acct, 120.00, 'hotel_folio:night:'.$stay->id.':2026-09-01:folio', 'Overnight — Deluxe');
        $this->seedEntry($acct, 120.00, 'hotel_folio:night:'.$stay->id.':2026-09-02:folio', 'Overnight — Deluxe');
        // A hotel service line.
        $this->seedEntry($acct, 45.00, 'hotel_svc:'.$stay->id.':7:'.Str::uuid().':folio', 'Spa massage');
        // An unrelated room-service order charge → "Charges" (other) bucket.
        $this->seedEntry($acct, 30.00, 'hotel_folio:order:99:folio', 'Room service', ['order_id' => 99]);

        $html = $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]))
            ->assertOk()
            ->assertSee('Overnight — Deluxe')      // night line, grouped under Room
            ->assertSee('Spa massage')             // service line, grouped under Services
            ->assertSee('Services')                // the services group header
            ->assertSee('#99')                     // the order charge under Charges
            ->getContent();

        // The date label for a night must render (isoFormat ddd D MMM of 2026-09-01).
        $this->assertStringContainsString('Sep', $html);
    }

    public function test_folio_without_grouped_refs_still_lists_charges(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $acct = HotelFolioService::folioAccountFor($stay);

        // Only an ungrouped order charge — no Room/Services sections should appear.
        $this->seedEntry($acct, 30.00, 'hotel_folio:order:5:folio', 'Room service', ['order_id' => 5]);

        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]))
            ->assertOk()
            ->assertSee('#5')
            ->assertDontSee('Services');
    }
}
