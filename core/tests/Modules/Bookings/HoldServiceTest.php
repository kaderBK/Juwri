<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings;

use App\Services\Entitlements;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\BookingHold;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Services\BookingPlan;
use Juwri\Modules\Bookings\Services\BookingSetup;
use Juwri\Modules\Bookings\Services\HoldService;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * P3-10: 10-minute holds and the double-booking guard (sqlite here; the cross-connection race runs on MySQL and
 * PostgreSQL in BookingDoubleBookingRaceTest), "no hours = no bookings" (D13), at-home radius, hold limits.
 */
class HoldServiceTest extends TestCase
{
    use BookingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (BookingException $e) {
            return $e->errorCode;
        }

        return 'none';
    }

    public function test_a_hold_blocks_its_cells_for_ten_minutes(): void
    {
        [$store, $service, $staff] = $this->readyStore(serviceAttrs: ['buffer_before_min' => 10, 'buffer_after_min' => 5]);
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', 'k1');

        $hold = $made['hold'];
        $this->assertSame(40, strlen($made['token']));
        $this->assertSame(hash('sha256', $made['token']), $hold->token_hash);
        $this->assertSame('2026-10-04 03:10:00', $hold->expires_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(15, DB::table('booking_slot_cells')->where('hold_id', $hold->id)->count()); // 09:50–11:05
    }

    public function test_an_overlapping_second_hold_is_refused_and_a_neighbour_is_not(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $holds = app(HoldService::class);
        $holds->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', 'a');

        $this->assertSame('slot_taken', $this->code(fn () => $holds->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:30'), 'venue', 'b')));
        $this->assertSame('none', $this->code(fn () => $holds->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 11:00'), 'venue', 'b')));
    }

    public function test_the_database_guard_refuses_an_overlap_even_when_the_free_check_is_skipped(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);

        $this->expectException(UniqueConstraintViolationException::class);
        app(HoldService::class)->insertCells($store, $staff->id, [$this->local($store, '2026-10-05 10:30')->getTimestamp()], ['booking_id' => 999]);
    }

    public function test_an_expired_hold_frees_its_time(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $holds = app(HoldService::class);
        $first = $holds->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', 'a')['hold'];

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:10:01', 'UTC'));
        $second = $holds->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', 'b')['hold'];

        $this->assertSame('expired', $first->fresh()->status);
        $this->assertSame(0, DB::table('booking_slot_cells')->where('hold_id', $first->id)->count());
        $this->assertSame(12, DB::table('booking_slot_cells')->where('hold_id', $second->id)->count());
    }

    public function test_release_frees_the_time_at_once(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $holds = app(HoldService::class);
        $hold = $holds->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', 'a')['hold'];
        $holds->release($hold);

        $this->assertSame('released', $hold->fresh()->status);
        $this->assertSame('none', $this->code(fn () => $holds->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', 'b')));
    }

    public function test_any_staff_takes_the_next_free_staff_member(): void
    {
        [$store, $service, $maha] = $this->readyStore();
        $sara = $this->staffMember($store, [$service], 'Sara', 1);
        $holds = app(HoldService::class);

        $a = $holds->hold($store, [$service->id], null, $this->local($store, '2026-10-05 10:00'), 'venue', 'a')['hold'];
        $b = $holds->hold($store, [$service->id], null, $this->local($store, '2026-10-05 10:00'), 'venue', 'b')['hold'];
        $this->assertSame([$maha->id, $sara->id], [$a->staff_id, $b->staff_id]);
        $this->assertSame('slot_taken', $this->code(fn () => $holds->hold($store, [$service->id], null, $this->local($store, '2026-10-05 10:00'), 'venue', 'c')));
    }

    public function test_only_staff_who_do_every_chosen_service_are_used(): void
    {
        [$store, $cut, $maha] = $this->readyStore();
        $colour = $this->service($store, ['name' => 'Colour', 'duration_min' => 90]);
        $this->staffMember($store, [$colour], 'Sara', 1);

        $this->assertSame('invalid_request', $this->code(fn () => app(HoldService::class)->hold($store, [$cut->id, $colour->id], $maha->id, $this->local($store, '2026-10-05 10:00'), 'venue', 'a')));
        $this->assertSame('slot_taken', $this->code(fn () => app(HoldService::class)->hold($store, [$cut->id, $colour->id], null, $this->local($store, '2026-10-05 10:00'), 'venue', 'a'))); // nobody does both
    }

    public function test_a_start_time_that_is_not_a_slot_is_refused(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $this->assertSame('slot_taken', $this->code(fn () => app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:07'), 'venue', null)));
        $this->assertSame('slot_taken', $this->code(fn () => app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 16:30'), 'venue', null))); // would end after 17:00
        $this->assertSame('slot_taken', $this->code(fn () => app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 07:00'), 'venue', null)));
    }

    public function test_one_holder_has_at_most_three_live_holds(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $holds = app(HoldService::class);
        foreach (['10:00', '11:00', '12:00'] as $t) {
            $holds->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 '.$t), 'venue', 'same-holder');
        }
        $this->assertSame('too_many_holds', $this->code(fn () => $holds->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 14:00'), 'venue', 'same-holder')));
        $this->assertSame('none', $this->code(fn () => $holds->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 14:00'), 'venue', 'other-holder')));
    }

    public function test_no_hours_means_no_bookings(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        BookingHour::query()->where('store_id', $store->id)->delete();

        $this->assertFalse(BookingSetup::isOpen($store));
        $this->assertFalse(BookingSetup::checklist($store)['hours']);
        $this->assertSame('bookings_not_open', $this->code(fn () => app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null)));
    }

    public function test_staff_hours_alone_do_not_open_bookings(): void
    {
        $store = $this->servicesStore();
        $service = $this->service($store);
        $staff = $this->staffMember($store, [$service]);
        $this->weeklyHours($store, staff: $staff);

        $this->assertFalse(BookingSetup::checklist($store)['hours']);
    }

    public function test_the_booking_plan_key_gates_new_holds_and_a_missing_key_allows(): void
    {
        [$open] = $this->readyStore();
        $this->assertTrue(BookingPlan::storeAllows($open, BookingPlan::BOOKING)); // the plan holds no `booking` value
        $this->assertTrue(BookingSetup::isOpen($open));

        $owner = $this->ownerWithPlan(['booking' => '0']);
        if ((string) (Entitlements::planTrace($owner, BookingPlan::BOOKING)['value'] ?? '') !== '0') {
            $this->markTestSkipped('This edition grants every plan key (Community): nothing to withhold.');
        }
        [$store, $service, $staff] = $this->readyStore(owner: $owner);

        $this->assertFalse(BookingPlan::allows($owner, BookingPlan::BOOKING));
        $this->assertFalse(BookingSetup::checklist($store)['plan']);
        $this->assertSame('bookings_not_open', $this->code(fn () => app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null)));
    }

    public function test_at_home_needs_a_position_inside_the_radius(): void
    {
        [$store, $service, $staff] = $this->readyStore(settings: [
            'booking_at_home' => true, 'booking_at_home_radius_km' => 10, 'booking_base_lat' => '24.7136', 'booking_base_lng' => '46.6753',
        ], serviceAttrs: ['at_home' => true]);
        $holds = app(HoldService::class);
        $at = $this->local($store, '2026-10-05 10:00');

        $this->assertSame('invalid_request', $this->code(fn () => $holds->hold($store, [$service->id], $staff->id, $at, 'at_home', null)));
        $this->assertSame('outside_area', $this->code(fn () => $holds->hold($store, [$service->id], $staff->id, $at, 'at_home', null, ['lat' => 21.4858, 'lng' => 39.1925]))); // Jeddah
        $this->assertSame('none', $this->code(fn () => $holds->hold($store, [$service->id], $staff->id, $at, 'at_home', null, ['lat' => 24.75, 'lng' => 46.70])));
    }

    public function test_a_service_not_offered_at_home_is_refused_there(): void
    {
        [$store, $service, $staff] = $this->readyStore(settings: ['booking_at_home' => true]);
        $this->assertSame('invalid_request', $this->code(fn () => app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'at_home', null, ['lat' => 1.0, 'lng' => 1.0])));
    }

    public function test_holds_of_another_store_never_see_these_services(): void
    {
        [$store, $service] = $this->readyStore();
        [$other, , $otherStaff] = $this->readyStore();

        $this->assertSame('invalid_request', $this->code(fn () => app(HoldService::class)->hold($other, [$service->id], $otherStaff->id, $this->local($other, '2026-10-05 10:00'), 'venue', null)));
        $this->assertSame(0, BookingHold::query()->count());
    }
}
