<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHold;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Bookings\Services\ManageLink;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * P3-10: the plain storefront booking flow end to end (services → time → hold → details → booking page), the
 * "Bookings open soon" state (D13), .ics, reschedule / cancel through the signed manage link and its tamper checks.
 */
class BookingStorefrontFlowTest extends TestCase
{
    use BookingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_store_without_hours_says_bookings_open_soon(): void
    {
        [$store] = $this->readyStore();
        BookingHour::query()->where('store_id', $store->id)->delete();

        $this->get('/'.$store->slug.'/appointments')->assertOk()->assertSee('Bookings open soon')->assertSee('data-testid="bookings-open-soon"', false);
        $this->get('/'.$store->slug.'/appointments/times?services[]=1')->assertRedirect();
    }

    public function test_a_store_that_is_not_a_services_store_has_no_booking_page(): void
    {
        [$store] = $this->readyStore();
        $store->forceFill(['store_type' => 'restaurant'])->save();

        $this->get('/'.$store->slug.'/appointments')->assertNotFound();
    }

    public function test_the_whole_flow_without_a_deposit(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $slug = $store->slug;

        $this->get('/'.$slug.'/appointments')->assertOk()->assertSee('Haircut')->assertSee('1 h');
        $times = $this->get('/'.$slug.'/appointments/times?services[]='.$service->id.'&date=2026-10-05');
        $times->assertOk()->assertSee('Times are in Riyadh time (GMT+03:00).')->assertSee('09:00')->assertSee('16:00');

        $start = $this->local($store, '2026-10-05 10:00')->getTimestamp();
        $hold = $this->post('/'.$slug.'/appointments/hold', ['services' => [$service->id], 'starts_at' => $start]);
        $row = BookingHold::query()->firstOrFail();
        $hold->assertRedirect(route('bookings.details', ['slug' => $slug, 'hold' => $row->public_id]));

        $this->get('/'.$slug.'/appointments/details/'.$row->public_id)->assertOk()
            ->assertSee('Confirm booking')->assertSee('Free cancellation or changes until');

        $key = (string) Str::uuid7();
        $book = $this->post('/'.$slug.'/appointments/book', ['hold' => $row->public_id, 'client_key' => $key, 'name' => 'Noura', 'phone' => '+966500000001', 'remind_email' => '1', 'email' => 'n@example.com']);
        $booking = Booking::query()->firstOrFail();
        $token = ManageLink::token($booking);
        $book->assertRedirect(route('bookings.manage', ['slug' => $slug, 'token' => $token]));
        $this->assertTrue($booking->remind_email);
        $this->assertFalse($booking->remind_sms);

        $this->get('/'.$slug.'/appointments/m/'.$token)->assertOk()
            ->assertSee('data-status="confirmed"', false)->assertSee($booking->number)->assertSee('Add to calendar');

        $ics = $this->get('/'.$slug.'/appointments/m/'.$token.'/calendar');
        $ics->assertOk();
        $this->assertStringStartsWith('text/calendar', (string) $ics->headers->get('Content-Type'));
        $body = $ics->getContent();
        $this->assertStringContainsString("BEGIN:VEVENT\r\n", $body);
        $this->assertStringContainsString('DTSTART:20261005T070000Z', $body);
        $this->assertStringContainsString('DTEND:20261005T080000Z', $body);
        $this->assertStringContainsString('UID:'.$booking->public_id.'@', $body);
        foreach (explode("\r\n", $body) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
    }

    public function test_the_session_that_did_not_make_a_hold_cannot_use_it(): void
    {
        [$store, $service] = $this->readyStore();
        $this->post('/'.$store->slug.'/appointments/hold', ['services' => [$service->id], 'starts_at' => $this->local($store, '2026-10-05 10:00')->getTimestamp()]);
        $row = BookingHold::query()->firstOrFail();

        $this->flushSession();
        $this->get('/'.$store->slug.'/appointments/details/'.$row->public_id)->assertRedirect(route('bookings.start', ['slug' => $store->slug]));
        $this->post('/'.$store->slug.'/appointments/book', ['hold' => $row->public_id, 'name' => 'X', 'phone' => '1'])->assertRedirect();
        $this->assertSame(0, Booking::query()->count());
    }

    public function test_a_taken_time_sends_the_customer_back_with_a_message(): void
    {
        [$store, $service] = $this->readyStore();
        $start = $this->local($store, '2026-10-05 10:00')->getTimestamp();
        $this->post('/'.$store->slug.'/appointments/hold', ['services' => [$service->id], 'starts_at' => $start]);
        $this->flushSession();

        $this->from('/'.$store->slug.'/appointments/times')
            ->post('/'.$store->slug.'/appointments/hold', ['services' => [$service->id], 'starts_at' => $start])
            ->assertRedirect('/'.$store->slug.'/appointments/times')
            ->assertSessionHasErrors(['booking' => 'This time was just taken. Please choose another time.']);
    }

    public function test_a_tampered_or_foreign_manage_link_is_not_found(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        [$other] = $this->readyStore();
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'N', 'phone' => '1']);
        $token = ManageLink::token($booking);
        [$id, $sig] = explode('.', $token);

        $this->get('/'.$store->slug.'/appointments/m/'.$token)->assertOk();
        $this->get('/'.$store->slug.'/appointments/m/'.$id.'.'.str_repeat('0', 40))->assertNotFound();
        $this->get('/'.$store->slug.'/appointments/m/'.$id.'.'.substr($sig, 0, 39).($sig[39] === 'a' ? 'b' : 'a'))->assertNotFound();
        $this->get('/'.$store->slug.'/appointments/m/'.$id)->assertNotFound();
        $this->get('/'.$store->slug.'/appointments/m/'.Str::uuid7().'.'.$sig)->assertNotFound();
        $this->get('/'.$other->slug.'/appointments/m/'.$token)->assertNotFound(); // another store's page
        $this->post('/'.$store->slug.'/appointments/m/'.$id.'.'.str_repeat('0', 40).'/cancel')->assertNotFound();
        $this->get('/'.$store->slug.'/appointments/m/'.$id.'.'.str_repeat('0', 40).'/calendar')->assertNotFound();

        // rotating the nonce revokes every link sent before
        $booking->forceFill(['manage_nonce' => Str::random(32)])->save();
        $this->get('/'.$store->slug.'/appointments/m/'.$token)->assertNotFound();
    }

    public function test_reschedule_and_cancel_through_the_manage_link(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'N', 'phone' => '1']);
        $base = '/'.$store->slug.'/appointments/m/'.ManageLink::token($booking);

        $this->get($base.'/reschedule?date=2026-10-05')->assertOk()->assertSee('Choose a new time')->assertSee('Same price, nothing to pay.');
        $this->post($base.'/reschedule', ['starts_at' => $this->local($store, '2026-10-05 13:00')->getTimestamp()])
            ->assertRedirect($base)->assertSessionHas('success');
        $this->assertSame('2026-10-05 10:00', $booking->fresh()->starts_at->utc()->format('Y-m-d H:i'));

        $this->post($base.'/cancel')->assertRedirect()->assertSessionHas('success', 'Your booking is cancelled.');
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->get($base)->assertOk()->assertSee('data-status="cancelled"', false)->assertDontSee('Reschedule');
    }

    public function test_after_the_deadline_reschedule_is_refused_and_the_policy_says_so(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'N', 'phone' => '1']);
        $base = '/'.$store->slug.'/appointments/m/'.ManageLink::token($booking);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 08:00:00', 'UTC'));

        $this->get($base)->assertOk()->assertDontSee('>Reschedule<', false);
        $this->get($base.'/reschedule')->assertRedirect($base);
        $this->post($base.'/reschedule', ['starts_at' => $this->local($store, '2026-10-05 13:00')->getTimestamp()])->assertSessionHasErrors('booking');
        $this->assertSame('2026-10-05 07:00', $booking->fresh()->starts_at->utc()->format('Y-m-d H:i'));
    }

    public function test_a_deposit_booking_goes_to_the_payment_page_with_the_store_gateways(): void
    {
        [$store, $service] = $this->readyStore(serviceAttrs: ['deposit_type' => 'percent', 'deposit_value' => 50]);
        $this->post('/'.$store->slug.'/appointments/hold', ['services' => [$service->id], 'starts_at' => $this->local($store, '2026-10-05 10:00')->getTimestamp()]);
        $row = BookingHold::query()->firstOrFail();
        $this->get('/'.$store->slug.'/appointments/details/'.$row->public_id)->assertOk()->assertSee('Deposits are not refunded. You can reschedule until')->assertSee('Due now');

        $this->post('/'.$store->slug.'/appointments/book', ['hold' => $row->public_id, 'name' => 'N', 'phone' => '1']);
        $booking = Booking::query()->firstOrFail();
        $this->assertSame('pending_payment', $booking->status);
        $this->assertSame(5000, $booking->deposit_minor);
        $this->get('/'.$store->slug.'/appointments/m/'.ManageLink::token($booking).'/pay')->assertOk()->assertSee('Pay the deposit');
        // no gateway chosen that the store offers: refused, nothing opened
        $this->post('/'.$store->slug.'/appointments/m/'.ManageLink::token($booking).'/pay', ['payment_method' => 'nope'])->assertSessionHasErrors('payment_method');
        $this->assertSame(0, Transaction::query()->count());
    }
}
