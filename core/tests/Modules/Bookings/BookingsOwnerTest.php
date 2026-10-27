<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingClosedDay;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Models\BookingStaff;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\HoldService;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * P3-10 owner screens: setup checklist (D13), staff / services / hours / closed days / settings, booking actions —
 * and tenancy: another owner's store, staff, service, closed day or booking is never readable or writable (IDOR).
 */
class BookingsOwnerTest extends TestCase
{
    use BookingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $this->withoutMiddleware();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_new_store_sees_the_setup_checklist_and_every_screen_renders(): void
    {
        $store = $this->servicesStore();
        $owner = $store->user;
        $q = ['store_id' => $store->id];

        $this->actingAs($owner)->get(route('bookings.owner.index', $q))->assertOk()
            ->assertSee('Set up bookings')->assertSee('Set your hours')->assertSee('data-testid="booking-checklist"', false);
        $this->actingAs($owner)->get(route('bookings.owner.staff', $q))->assertOk()->assertSee('Add Staff');
        $this->actingAs($owner)->get(route('bookings.owner.services', $q))->assertOk()->assertSee('Add a service');
        $this->actingAs($owner)->get(route('bookings.owner.hours', $q))->assertOk()->assertSee('Business hours');
        $this->actingAs($owner)->get(route('bookings.owner.settings', $q))->assertOk()->assertSee('Refund deposits when a customer cancels in time');
    }

    public function test_the_owner_sets_up_services_staff_and_hours_and_the_page_opens(): void
    {
        $store = $this->servicesStore();
        $owner = $store->user;
        $q = ['store_id' => $store->id];

        $this->actingAs($owner)->post(route('bookings.owner.services.store', $q), [
            'name' => 'Haircut & blow-dry', 'price' => '180', 'duration_min' => 75, 'buffer_after_min' => 10,
            'deposit_type' => 'amount', 'deposit_amount' => '50', 'venue' => '1',
        ])->assertSessionHasNoErrors();
        $service = BookableService::query()->firstOrFail();
        $this->assertSame(18000, $service->price_minor);
        $this->assertSame(5000, $service->depositMinor());
        $this->assertSame('SAR', $service->currency);

        $this->actingAs($owner)->post(route('bookings.owner.staff.store', $q), ['name' => 'Maha', 'role' => 'Stylist', 'services' => [$service->id]])->assertSessionHasNoErrors();
        $days = [];
        foreach ([0, 1, 2, 3, 4] as $d) {
            $days[$d] = ['open' => '1', 'starts' => '10:00', 'ends' => '20:00', 'break_starts' => '14:00', 'break_ends' => '15:00'];
        }
        $this->actingAs($owner)->post(route('bookings.owner.hours.save', $q), ['days' => $days])->assertSessionHasNoErrors();
        $this->assertSame(10, BookingHour::query()->where('store_id', $store->id)->count());

        $this->actingAs($owner)->get(route('bookings.owner.index', $q))->assertOk()->assertSee('Your booking page is open.');
        $this->get('/'.$store->slug.'/appointments')->assertOk()->assertSee('Haircut &amp; blow-dry', false);
    }

    public function test_validation_of_durations_hours_and_breaks(): void
    {
        $store = $this->servicesStore();
        $owner = $store->user;
        $q = ['store_id' => $store->id];

        $this->actingAs($owner)->post(route('bookings.owner.services.store', $q), ['name' => 'X', 'price' => '1', 'duration_min' => 33, 'deposit_type' => 'none', 'venue' => '1'])
            ->assertSessionHasErrors('duration_min');
        $this->actingAs($owner)->post(route('bookings.owner.services.store', $q), ['name' => 'X', 'price' => '1', 'duration_min' => 30, 'deposit_type' => 'none'])
            ->assertSessionHasErrors('venue');
        $this->actingAs($owner)->post(route('bookings.owner.hours.save', $q), ['days' => [1 => ['open' => '1', 'starts' => '18:00', 'ends' => '09:00']]])
            ->assertSessionHasErrors('days');
        $this->actingAs($owner)->post(route('bookings.owner.hours.save', $q), ['days' => [1 => ['open' => '1', 'starts' => '09:00', 'ends' => '17:00', 'break_starts' => '16:00', 'break_ends' => '18:00']]])
            ->assertSessionHasErrors('days');
        $this->actingAs($owner)->post(route('bookings.owner.hours.save', $q), ['days' => [1 => ['open' => '1', 'starts' => '09:07', 'ends' => '17:00']]])
            ->assertSessionHasErrors('days.1.starts');
        $this->assertSame(0, BookingHour::query()->count());
    }

    public function test_settings_are_saved_typed(): void
    {
        $store = $this->servicesStore();
        $this->actingAs($store->user)->post(route('bookings.owner.settings.save', ['store_id' => $store->id]), [
            'slot_interval' => 30, 'lead_minutes' => 120, 'max_days' => 30, 'cancel_window_hours' => 48, 'deposit_refunds' => '1',
            'at_home' => '1', 'at_home_radius_km' => 15, 'travel_buffer_min' => 20, 'base_lat' => '24.7', 'base_lng' => '46.6',
            'reminder_sms' => '1', 'no_show_fee_amount' => '25',
        ])->assertSessionHasNoErrors();

        $this->assertSame(30, StoreSettings::int($store->id, 'booking_slot_interval'));
        $this->assertSame(48, StoreSettings::int($store->id, 'booking_cancel_window_hours'));
        $this->assertTrue(StoreSettings::bool($store->id, 'booking_deposit_refunds'));
        $this->assertFalse(StoreSettings::bool($store->id, 'booking_reminder_email'));
        $this->assertTrue(StoreSettings::bool($store->id, 'booking_reminder_sms'));
        $this->assertSame(2500, StoreSettings::int($store->id, 'booking_no_show_fee_minor'));
        $this->assertFalse(StoreSettings::bool($store->id, 'booking_no_show_fee'));
    }

    public function test_staff_with_bookings_is_turned_off_not_deleted(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'N', 'phone' => '1']);

        $this->actingAs($store->user)->post(route('bookings.owner.staff.delete', ['staff' => $staff->id, 'store_id' => $store->id]))->assertSessionHas('success');
        $this->assertFalse($staff->fresh()->active);
        $this->actingAs($store->user)->post(route('bookings.owner.services.delete', ['service' => $service->id, 'store_id' => $store->id]));
        $this->assertFalse($service->fresh()->active);
    }

    public function test_owner_booking_actions(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'Noura', 'phone' => '1']);
        $q = ['booking' => $booking->id, 'store_id' => $store->id];

        $this->actingAs($store->user)->get(route('bookings.owner.index', ['store_id' => $store->id]))->assertOk()->assertSee('Noura')->assertSee($booking->number);
        $this->actingAs($store->user)->post(route('bookings.owner.action', $q), ['do' => 'complete'])->assertSessionHas('success');
        $this->assertSame('completed', $booking->fresh()->status);
        $this->actingAs($store->user)->post(route('bookings.owner.action', $q), ['do' => 'cancel'])->assertSessionHasErrors('booking');
    }

    public function test_another_owner_can_never_read_or_change_this_store(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'Noura', 'phone' => '1']);
        $closed = BookingClosedDay::query()->create(['store_id' => $store->id, 'date' => '2026-10-10']);

        $attacker = $this->servicesStore();
        $mallory = $attacker->user;
        $own = ['store_id' => $attacker->id];

        // the victim's store id in the query: refused outright
        $this->actingAs($mallory)->get(route('bookings.owner.index', ['store_id' => $store->id]))->assertForbidden();
        $this->actingAs($mallory)->post(route('bookings.owner.settings.save', ['store_id' => $store->id]), ['slot_interval' => 30, 'lead_minutes' => 0, 'max_days' => 5, 'cancel_window_hours' => 0])->assertForbidden();
        // the victim's row ids under the attacker's own store: not found
        $this->actingAs($mallory)->post(route('bookings.owner.staff.update', ['staff' => $staff->id] + $own), ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($mallory)->post(route('bookings.owner.staff.delete', ['staff' => $staff->id] + $own))->assertNotFound();
        $this->actingAs($mallory)->post(route('bookings.owner.services.update', ['service' => $service->id] + $own), ['name' => 'Hacked', 'price' => 1, 'duration_min' => 30, 'deposit_type' => 'none', 'venue' => '1'])->assertNotFound();
        $this->actingAs($mallory)->post(route('bookings.owner.services.delete', ['service' => $service->id] + $own))->assertNotFound();
        $this->actingAs($mallory)->post(route('bookings.owner.closed.delete', ['day' => $closed->id] + $own))->assertNotFound();
        $this->actingAs($mallory)->post(route('bookings.owner.action', ['booking' => $booking->id] + $own), ['do' => 'cancel'])->assertNotFound();
        $this->actingAs($mallory)->get(route('bookings.owner.hours', ['staff' => $staff->id] + $own))->assertNotFound();
        $this->actingAs($mallory)->post(route('bookings.owner.hours.save', $own), ['staff_id' => $staff->id, 'days' => []])->assertNotFound();
        $this->actingAs($mallory)->post(route('bookings.owner.closed.store', $own), ['date' => '2026-10-11', 'staff_id' => $staff->id])->assertNotFound();
        // assigning the victim's service to the attacker's staff is silently dropped
        $this->actingAs($mallory)->post(route('bookings.owner.staff.store', $own), ['name' => 'Eve', 'services' => [$service->id]]);
        $eve = BookingStaff::query()->where('name', 'Eve')->firstOrFail();
        $this->assertSame([], $eve->services()->pluck('booking_services.id')->all());

        $this->assertSame('Maha', $staff->fresh()->name);
        $this->assertSame('Haircut', $service->fresh()->name);
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertNotNull($closed->fresh());
        // the attacker's list never shows the victim's booking
        $this->actingAs($mallory)->get(route('bookings.owner.index', $own))->assertOk()->assertDontSee('Noura');
        $this->assertSame(1, Booking::query()->count());
    }
}
