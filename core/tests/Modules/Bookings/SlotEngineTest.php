<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Models\BookingClosedDay;
use Juwri\Modules\Bookings\Services\BookingSettings;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Bookings\Services\SlotEngine;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * P3-10: slot maths — interval steps, buffers, breaks, closed days, staff hours, lead time, horizon, time zones and
 * both DST changes, at-home travel buffers, expired holds.
 */
class SlotEngineTest extends TestCase
{
    use BookingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC')); // Sunday 06:00 Riyadh
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> local "H:i" of the free slots */
    private function free(Store $store, $services, $staff, string $date, string $location = 'venue'): array
    {
        $settings = BookingSettings::for((int) $store->id);
        $tz = $settings->timezone;

        return array_values(array_map(fn ($s) => $s['starts_at']->setTimezone($tz)->format('H:i'),
            array_filter(app(SlotEngine::class)->slots($settings, collect($services), $location, $staff, $date), fn ($s) => $s['free'])));
    }

    public function test_slots_step_by_the_interval_inside_the_hours_in_store_time(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $slots = app(SlotEngine::class)->slots(BookingSettings::for((int) $store->id), collect([$service]), 'venue', $staff, '2026-10-05');

        $this->assertCount(29, $slots); // 09:00 … 16:00 every 15 min (a 60-min service must end by 17:00)
        $this->assertSame('2026-10-05 06:00:00', $slots[0]['starts_at']->utc()->format('Y-m-d H:i:s')); // 09:00 Riyadh (UTC+3)
        $this->assertSame('16:00', end($slots)['starts_at']->setTimezone('Asia/Riyadh')->format('H:i'));
    }

    public function test_buffers_keep_time_free_around_a_booking_and_are_counted_for_new_slots(): void
    {
        [$store, $service, $staff] = $this->readyStore(serviceAttrs: ['buffer_after_min' => 15]);
        app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);

        $free = $this->free($store, [$service], $staff, '2026-10-05');
        $this->assertNotContains('09:00', $free); // 09:00–10:00 + 15 min buffer runs into 10:00
        $this->assertNotContains('10:45', $free);
        $this->assertNotContains('11:00', $free); // the 10:00 booking's own buffer holds 11:00–11:15
        $this->assertContains('11:15', $free);
    }

    public function test_a_break_cuts_the_working_window(): void
    {
        $store = $this->servicesStore();
        $service = $this->service($store);
        $staff = $this->staffMember($store, [$service]);
        $this->weeklyHours($store, '09:00', '17:00', ['12:00', '13:00']);

        $free = $this->free($store, [$service], $staff, '2026-10-05');
        $this->assertContains('11:00', $free);
        $this->assertNotContains('11:15', $free);
        $this->assertNotContains('12:30', $free);
        $this->assertContains('13:00', $free);
    }

    public function test_store_and_staff_closed_days(): void
    {
        [$store, $service, $maha] = $this->readyStore();
        $sara = $this->staffMember($store, [$service], 'Sara', 1);

        BookingClosedDay::query()->create(['store_id' => $store->id, 'staff_id' => $maha->id, 'date' => '2026-10-06']);
        $this->assertSame([], $this->free($store, [$service], $maha, '2026-10-06'));
        $this->assertNotSame([], $this->free($store, [$service], $sara, '2026-10-06'));

        BookingClosedDay::query()->create(['store_id' => $store->id, 'staff_id' => null, 'date' => '2026-10-07']);
        $this->assertSame([], $this->free($store, [$service], $sara, '2026-10-07'));
    }

    public function test_a_staff_member_with_own_hours_uses_only_those(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $this->weeklyHours($store, '12:00', '14:00', null, $staff, [1]); // Mondays only

        $this->assertSame(['12:00', '12:15', '12:30', '12:45', '13:00'], $this->free($store, [$service], $staff, '2026-10-05'));
        $this->assertSame([], $this->free($store, [$service], $staff, '2026-10-06')); // Tuesday: no own row = not working
    }

    public function test_lead_time_and_horizon(): void
    {
        [$store, $service, $staff] = $this->readyStore(settings: ['booking_lead_minutes' => 60, 'booking_max_days' => 2]);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 09:20', 'Asia/Riyadh'));

        $this->assertSame('10:30', $this->free($store, [$service], $staff, '2026-10-05')[0]); // now + 60 min = 10:20
        $this->assertNotSame([], $this->free($store, [$service], $staff, '2026-10-07'));
        $this->assertSame([], $this->free($store, [$service], $staff, '2026-10-08')); // beyond 2 days
        $this->assertSame([], $this->free($store, [$service], $staff, '2026-10-04')); // the past
    }

    public function test_spring_forward_day_has_only_the_real_hours(): void
    {
        $store = $this->servicesStore('America/New_York', ['booking_slot_interval' => 60]);
        $service = $this->service($store);
        $staff = $this->staffMember($store, [$service]);
        $this->weeklyHours($store, '01:00', '05:00');
        Carbon::setTestNow(CarbonImmutable::parse('2026-03-01 00:00', 'UTC'));

        // 2026-03-08 02:00 EST → 03:00 EDT: 01:00–05:00 local is three real hours
        $this->assertSame(['01:00', '03:00', '04:00'], $this->free($store, [$service], $staff, '2026-03-08'));
        $slots = app(SlotEngine::class)->slots(BookingSettings::for((int) $store->id), collect([$service]), 'venue', $staff, '2026-03-08');
        $this->assertSame(['06:00', '07:00', '08:00'], array_map(fn ($s) => $s['starts_at']->utc()->format('H:i'), $slots));
    }

    public function test_fall_back_day_counts_the_repeated_hour_once_per_instant(): void
    {
        $store = $this->servicesStore('America/New_York', ['booking_slot_interval' => 60]);
        $service = $this->service($store);
        $staff = $this->staffMember($store, [$service]);
        $this->weeklyHours($store, '00:00', '04:00');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-25 00:00', 'UTC'));

        // 2026-11-01 02:00 EDT → 01:00 EST: 00:00–04:00 local is five real hours, 01:00 happens twice
        $this->assertSame(['00:00', '01:00', '01:00', '02:00', '03:00'], $this->free($store, [$service], $staff, '2026-11-01'));
    }

    public function test_at_home_visits_add_the_travel_buffer_on_both_sides(): void
    {
        [$store, $service, $staff] = $this->readyStore(settings: ['booking_at_home' => true, 'booking_travel_buffer_min' => 30], serviceAttrs: ['at_home' => true]);
        app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);

        $home = $this->free($store, [$service], $staff, '2026-10-05', 'at_home');
        $this->assertNotContains('11:00', $home); // travel from 10:30 overlaps the 10:00–11:00 visit
        $this->assertNotContains('08:45', $home);
        $this->assertContains('11:30', $home);
        $this->assertContains('11:00', $this->free($store, [$service], $staff, '2026-10-05')); // in the venue: no travel
    }

    public function test_expired_cells_do_not_block(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        DB::table('booking_slot_cells')->insert([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'staff_id' => $staff->id,
            'cell_at' => '2026-10-05 07:00:00', 'expires_at' => '2026-10-04 02:00:00',
        ]);
        $this->assertContains('10:00', $this->free($store, [$service], $staff, '2026-10-05'));
    }

    public function test_cells_cover_the_blocked_time_on_a_five_minute_grid(): void
    {
        $engine = app(SlotEngine::class);
        $cells = $engine->cells(CarbonImmutable::parse('2026-10-05 10:00', 'UTC'), CarbonImmutable::parse('2026-10-05 10:20', 'UTC'));
        $this->assertCount(4, $cells);
        $this->assertSame(CarbonImmutable::parse('2026-10-05 10:15', 'UTC')->getTimestamp(), end($cells));
        $this->assertSame('2026-10-05 07:00', $engine->instant('2026-10-05', '10:00', 'Asia/Riyadh')?->format('Y-m-d H:i'));
        $this->assertSame('2026-10-05 21:00', $engine->instant('2026-10-05', '24:00', 'Asia/Riyadh')?->format('Y-m-d H:i'));
        $this->assertNull($engine->instant('2026-10-05', '25:00', 'Asia/Riyadh'));
    }
}
