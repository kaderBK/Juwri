<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHold;
use Juwri\Modules\Bookings\Models\BookingStaff;
use Juwri\Modules\Bookings\Services\ManageLink;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * P3-11: the `book` kit theme renders the booking engine's whole flow — the store page (services), the service sheet, choose a
 * time, confirm, the booking page, reschedule — from the view model, and a store that does not sell through the engine keeps
 * the kit's ordering page.
 */
class KitBookRenderTest extends TestCase
{
    use BookingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Store, 1: BookableService, 2: BookingStaff} */
    private function bookStore(array $service = []): array
    {
        [$store, $svc, $staff] = $this->readyStore('Asia/Riyadh', [], $service + ['description' => 'Cut and finish.']);
        StoreOption::updateStoreOption($store->id, 'store_template', 'book');

        return [$store, $svc, $staff];
    }

    public function test_the_store_page_lists_the_services_as_a_booking_form(): void
    {
        [$store, $service] = $this->bookStore();
        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-theme-slug="book"', $html);
        $this->assertStringContainsString('c-svc', $html);
        $this->assertStringContainsString('Haircut', $html);
        $this->assertStringContainsString('name="services[]" value="'.$service->public_id.'"', $html);
        $this->assertStringContainsString('action="/'.$store->slug.'/appointments/times"', $html);
        $this->assertStringNotContainsString('data-juwri-boot', $html, 'a booking page runs no cart core');
        $this->assertStringContainsString('data-book-boot', $html);
    }

    public function test_the_service_sheet_is_the_fragment_and_the_deep_link(): void
    {
        [$store, $service] = $this->bookStore();
        $sheet = $this->get('/'.$store->slug.'?fragment=item&item='.$service->public_id)->assertOk()->getContent();
        $this->assertStringContainsString('id="item-sheet"', $sheet);
        $this->assertStringContainsString('data-book-sheet-form', $sheet);
        $this->assertStringContainsString('Add to booking', $sheet);

        $page = $this->get('/'.$store->slug.'?item='.$service->public_id)->assertOk()->getContent();
        $this->assertStringContainsString('id="item-sheet"', $page);
        $this->assertStringNotContainsString(' hidden', substr($page, (int) strpos($page, 'id="item-sheet"'), 200));
    }

    public function test_the_booking_flow_runs_on_the_theme_pages(): void
    {
        [$store, $service] = $this->bookStore(['deposit_type' => 'amount', 'deposit_value' => 2000]);
        $slug = $store->slug;

        // the plain start page hands over to the store page
        $this->get('/'.$slug.'/appointments')->assertRedirect('/'.$slug);

        $times = $this->get('/'.$slug.'/appointments/times?services[]='.$service->public_id.'&date=2026-10-05')->assertOk()->getContent();
        $this->assertStringContainsString('data-theme-slug="book"', $times);
        $this->assertStringContainsString('class="c-slot', $times);
        $this->assertStringContainsString('form="tp-hold"', $times);
        $this->assertStringContainsString('Times are in', $times);

        $start = $this->local($store, '2026-10-05 10:00')->getTimestamp();
        $this->post('/'.$slug.'/appointments/hold', ['services' => [$service->public_id], 'starts_at' => $start])->assertRedirect();
        $hold = BookingHold::query()->firstOrFail();

        $details = $this->get('/'.$slug.'/appointments/details/'.$hold->public_id)->assertOk()->getContent();
        $this->assertStringContainsString('p-checkout', $details);
        $this->assertStringContainsString('name="phone"', $details);
        $this->assertStringContainsString('Pay a deposit now', $details);
        $this->assertStringContainsString('20.00', $details);

        $this->post('/'.$slug.'/appointments/book', ['hold' => $hold->public_id, 'name' => 'Noura Al-Harbi', 'phone' => '+966500000001'])->assertRedirect();
        $booking = Booking::query()->firstOrFail();
        $token = ManageLink::token($booking);

        $pay = $this->get('/'.$slug.'/appointments/m/'.$token.'/pay')->assertOk()->getContent();
        $this->assertStringContainsString('p-pay', $pay);
        $this->assertStringContainsString('Pay the deposit', $pay);

        $booking->forceFill(['status' => 'confirmed', 'deposit_status' => 'paid'])->save();
        $manage = $this->get('/'.$slug.'/appointments/m/'.$token)->assertOk()->getContent();
        $this->assertStringContainsString('p-track', $manage);
        $this->assertStringContainsString("You're booked, Noura", str_replace('&#039;', "'", $manage));
        $this->assertStringContainsString('Add to calendar', $manage);
        $this->assertStringContainsString('Cancel booking', $manage);

        $re = $this->get('/'.$slug.'/appointments/m/'.$token.'/reschedule?date=2026-10-06')->assertOk()->getContent();
        $this->assertStringContainsString('p-reschedule', $re);
        $this->assertStringContainsString('Current booking', $re);
        $this->assertStringContainsString('Confirm new time', $re);
    }

    public function test_a_refused_step_says_why_on_the_theme_page(): void
    {
        [$store, $service] = $this->bookStore();
        $res = $this->from('/'.$store->slug.'/appointments/times')->followingRedirects()->get('/'.$store->slug.'/appointments/details/'.Str::uuid());
        $res->assertOk();
        $this->assertStringContainsString('data-theme-slug="book"', $res->getContent());
    }

    public function test_a_book_store_without_bookable_services_keeps_the_ordering_page(): void
    {
        $store = $this->servicesStore();
        StoreOption::updateStoreOption($store->id, 'store_template', 'book');
        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-theme-slug="book"', $html);
        $this->assertStringNotContainsString('c-bkmain', $html);
        $this->assertStringContainsString('data-juwri-boot', $html);
    }

    public function test_the_old_booking_theme_renders_as_book_when_aliases_are_on(): void
    {
        [$store] = $this->readyStore();
        StoreOption::updateStoreOption($store->id, 'store_template', 'bookla');

        config(['theme-successors.enabled' => false]);
        $off = $this->get('/'.$store->slug)->assertOk()->getContent();
        $this->assertStringNotContainsString('data-theme-slug="book"', $off);

        config(['theme-successors.enabled' => true]);
        $on = $this->get('/'.$store->slug)->assertOk()->getContent();
        $this->assertStringContainsString('data-theme-slug="book"', $on);
        $this->assertStringContainsString('c-svc', $on);
        $this->get('/'.$store->slug.'/appointments')->assertRedirect('/'.$store->slug);
    }
}
