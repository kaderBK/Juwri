<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHold;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\HoldService;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * P3-11 (found while racing laundry holds in a separate process): the platform's own timezone setting makes the
 * application's default timezone something other than UTC (SettingsServiceProvider). Booking and hold times are UTC
 * instants in the database, so they must read back as the same instants whatever the application timezone is.
 */
class BookingAppTimezoneTest extends TestCase
{
    use BookingFixtures;
    use RefreshDatabase;

    private string $previousTz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTz = date_default_timezone_get();
        config(['app.timezone' => 'Asia/Kolkata']);
        date_default_timezone_set('Asia/Kolkata');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->previousTz);
        config(['app.timezone' => $this->previousTz]);
        parent::tearDown();
    }

    public function test_hold_and_booking_times_are_the_same_instants_after_a_database_round_trip(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $start = $this->local($store, '2026-10-05 10:00'); // 07:00 UTC
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $start, 'venue', null);

        $hold = BookingHold::query()->findOrFail($made['hold']->id);
        $this->assertSame('2026-10-05 07:00', $hold->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-05 08:00', $hold->ends_at->utc()->format('Y-m-d H:i'));
        $this->assertTrue($hold->isLive(), 'a hold made now is live');
        $this->assertSame(10, (int) round(now()->diffInSeconds($hold->expires_at, false) / 60), 'it expires in ten minutes');

        $booking = app(BookingService::class)->book($store, $hold->public_id, $made['token'], ['name' => 'Noura', 'phone' => '+966500000001']);
        $fresh = Booking::query()->findOrFail($booking->id);
        $this->assertSame('2026-10-05 07:00', $fresh->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-04 07:00', $fresh->cancel_deadline_at->utc()->format('Y-m-d H:i'), '24 h before the visit');
        $this->assertTrue($fresh->beforeDeadline());
    }

    public function test_a_second_hold_for_the_same_time_is_refused_in_a_non_utc_application(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $start = $this->local($store, '2026-10-05 10:00');
        app(HoldService::class)->hold($store, [$service->id], $staff->id, $start, 'venue', null);

        try {
            app(HoldService::class)->hold($store, [$service->id], $staff->id, $start, 'venue', null);
            $this->fail('the time is held');
        } catch (BookingException $e) {
            $this->assertSame('slot_taken', $e->errorCode);
        }
    }
}
