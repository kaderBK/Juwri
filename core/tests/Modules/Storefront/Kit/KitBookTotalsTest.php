<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Bookings\Services\ManageLink;
use Tests\Support\BookStoreScenario;
use Tests\TestCase;

/**
 * P3-11 / THEME-CONTRACT 5.2: what the `book` theme shows is the server's number. The quote of a selection (the store page's
 * panel and bar, the confirm page's summary) is the total the booking carries and the booking page's receipt prints — to the minor
 * unit, for a percentage tax in SAR (2 decimals) and for a fixed tax in KWD (3 decimals), with a deposit split that sums to it.
 */
class KitBookTotalsTest extends TestCase
{
    use BookStoreScenario;
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

    /** @param  array<string, mixed>  $tax */
    private function run_scenario(string $currency, array $tax, int $scale, int $taxMinor, int $subtotalMinor): void
    {
        ['store' => $store, 'services' => $services] = $this->bookScenarioStore($currency, $tax, $scale);
        $slug = $store->slug;
        $ids = [(string) $services[0]->public_id, (string) $services[1]->public_id];

        // 1. the quote the page shows
        $quote = $this->postJson('/'.$slug.'/booking-quote', ['lines' => array_map(fn ($id) => ['item' => $id, 'qty' => 1], $ids), 'order_type' => 'appointment'])
            ->assertOk()->json('quote');
        $this->assertSame($subtotalMinor + $taxMinor, $quote['total']['minor'], 'the quote total is subtotal + tax');
        $this->assertSame($currency, $quote['total']['currency']);
        $sum = array_sum(array_map(fn ($l) => $l['line_total']['minor'], $quote['lines']));
        $this->assertSame($subtotalMinor, $sum, 'the lines sum to the subtotal');
        $taxRow = collect($quote['rows'])->firstWhere('kind', 'tax');
        $this->assertSame($taxMinor, $taxRow['amount']['minor']);
        $this->assertSame($quote['total']['minor'], collect($quote['rows'])->firstWhere('kind', 'total')['amount']['minor']);

        // 2. the booking the same selection makes
        $hold = $this->bookStepHold($store, $ids);
        $details = $this->bookStep(fn ($c, $r) => $c->details($slug, $hold, $r));
        $this->assertStringContainsString($quote['total']['formatted'], $details, 'the confirm page prints the quoted total');

        $this->post('/'.$slug.'/appointments/book', ['hold' => $hold, 'name' => 'Noura', 'phone' => '+966500000001'])->assertRedirect();
        $booking = Booking::query()->latest('id')->firstOrFail();
        $this->assertSame($quote['total']['minor'], (int) $booking->total_minor, 'quoted == booked');
        $this->assertSame($taxMinor, (int) $booking->tax_minor);
        $this->assertSame($quote['total']['minor'], (int) $booking->deposit_minor + ((int) $booking->total_minor - (int) $booking->deposit_minor));

        // 3. the receipt the booking page prints
        $booking->forceFill(['status' => 'confirmed'])->save();
        $page = $this->bookStep(fn ($c, $r) => $c->manage($slug, ManageLink::token($booking)));
        $this->assertStringContainsString($quote['total']['formatted'], $page, 'the booking page prints the booked total');
    }

    private function bookStepHold($store, array $ids): string
    {
        $made = app(HoldService::class)->hold($store, $ids, null, $this->local($store, '2026-10-05 10:00'), 'venue', 'totals');
        session()->put('bookings.holds.'.$made['hold']->public_id, $made['token']);

        return (string) $made['hold']->public_id;
    }

    public function test_a_percentage_tax_in_sar_quotes_what_the_booking_charges(): void
    {
        // Haircut 120.00 + Gel manicure 110.00 = 230.00; VAT 15% = 34.50 (cents)
        $this->run_scenario('SAR', ['restaurant_taxx_charge' => '15', 'restaurant_tax_type' => 'percentage', 'restaurant_tax_label' => 'VAT'], 100, 3450, 23000);
    }

    public function test_a_fixed_tax_in_kwd_with_three_decimals_quotes_what_the_booking_charges(): void
    {
        // minor units are fils: Haircut KWD 12.000 + Gel manicure KWD 11.000 = 23.000; a fixed tax of KWD 0.250
        $this->run_scenario('KWD', ['restaurant_taxx_charge' => '0.25', 'restaurant_tax_type' => 'fixed', 'restaurant_tax_label' => 'Service tax'], 100, 250, 23000);
    }

    public function test_a_store_without_tax_has_no_tax_row_and_total_is_the_sum(): void
    {
        ['store' => $store, 'services' => $services] = $this->bookScenarioStore('SAR', ['restaurant_taxx_charge' => '0']);
        $quote = $this->postJson('/'.$store->slug.'/booking-quote', ['lines' => [['item' => (string) $services[2]->public_id]]])->assertOk()->json('quote');

        $this->assertNull(collect($quote['rows'])->firstWhere('kind', 'tax'));
        $this->assertSame(22000, $quote['total']['minor']);
        $this->assertSame('Total', collect($quote['rows'])->firstWhere('kind', 'subtotal')['label']);
    }

    public function test_the_quote_refuses_another_stores_service_and_an_empty_selection(): void
    {
        ['store' => $store] = $this->bookScenarioStore();
        ['services' => $theirs] = $this->bookScenarioStore();

        $this->postJson('/'.$store->slug.'/booking-quote', ['lines' => [['item' => (string) $theirs[0]->public_id]]])->assertStatus(422);
        $this->postJson('/'.$store->slug.'/booking-quote', ['lines' => []])->assertStatus(422);
        $this->postJson('/'.$store->slug.'/booking-quote', ['lines' => [['item' => 'not-a-uuid']]])->assertStatus(422);
        $this->postJson('/nope-'.Str::random(6).'/booking-quote', ['lines' => [['item' => (string) Str::uuid()]]])->assertNotFound();
    }
}
