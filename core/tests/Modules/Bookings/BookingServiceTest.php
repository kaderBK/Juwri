<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\HoldService;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * P3-10: booking from a hold, idempotency, cancel, reschedule rules (same price, until the deadline: API-8/9),
 * complete / no-show.
 */
class BookingServiceTest extends TestCase
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

    /** @return array{0: Booking, 1: Store, 2: mixed, 3: mixed} */
    private function booked(string $at = '2026-10-05 10:00', array $settings = [], ?string $key = null): array
    {
        [$store, $service, $staff] = $this->readyStore(settings: $settings);
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, $at), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'Noura', 'phone' => '+966500000001', 'email' => 'n@example.com'], $key);

        return [$booking, $store, $service, $staff];
    }

    public function test_a_hold_without_deposit_becomes_a_confirmed_booking_that_keeps_the_time(): void
    {
        [$booking, $store] = $this->booked();

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('none', $booking->deposit_status);
        $this->assertSame(10000, $booking->total_minor);
        $this->assertMatchesRegularExpression('/^B-[2-9A-HJKMNP-Z]{6}$/', $booking->number);
        $this->assertSame('2026-10-04 07:00', $booking->cancel_deadline_at->utc()->format('Y-m-d H:i')); // 24 h before 10:00 Riyadh
        $this->assertSame(12, DB::table('booking_slot_cells')->where('booking_id', $booking->id)->whereNull('expires_at')->count());
        $this->assertSame(0, DB::table('booking_slot_cells')->whereNotNull('hold_id')->count());
        $this->assertSame(['Haircut'], $booking->lines->pluck('name')->all());
    }

    public function test_a_resend_with_the_same_client_key_returns_the_same_booking(): void
    {
        $key = (string) Str::uuid7();
        [$store, $service, $staff] = $this->readyStore();
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $customer = ['name' => 'Noura', 'phone' => '+966500000001'];
        $first = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], $customer, $key);
        $again = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], $customer, $key);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Booking::query()->count());

        $other = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 12:00'), 'venue', null);
        $this->assertSame('idempotency_conflict', $this->code(fn () => app(BookingService::class)->book($store, $other['hold']->public_id, $other['token'], $customer, $key)));
    }

    public function test_a_hold_is_used_once_needs_its_token_and_expires(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $svc = app(BookingService::class);
        $customer = ['name' => 'Noura', 'phone' => '1'];
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);

        $this->assertSame('hold_not_found', $this->code(fn () => $svc->book($store, $made['hold']->public_id, 'wrong-token', $customer)));
        $svc->book($store, $made['hold']->public_id, $made['token'], $customer);
        $this->assertSame('hold_expired', $this->code(fn () => $svc->book($store, $made['hold']->public_id, $made['token'], $customer)));

        $late = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 12:00'), 'venue', null);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:10:01', 'UTC'));
        $this->assertSame('hold_expired', $this->code(fn () => $svc->book($store, $late['hold']->public_id, $late['token'], $customer)));
    }

    public function test_a_hold_of_another_store_is_not_found(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        [$other] = $this->readyStore();
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);

        $this->assertSame('hold_not_found', $this->code(fn () => app(BookingService::class)->book($other, $made['hold']->public_id, $made['token'], ['name' => 'X', 'phone' => '1'])));
    }

    public function test_name_and_phone_are_required(): void
    {
        [$store, $service, $staff] = $this->readyStore();
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $this->assertSame('invalid_request', $this->code(fn () => app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'Noura', 'phone' => ''])));
    }

    public function test_cancel_frees_the_time_and_cannot_run_twice(): void
    {
        [$booking, $store, $service, $staff] = $this->booked();
        $this->assertSame('none', app(BookingService::class)->cancel($booking));

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(0, DB::table('booking_slot_cells')->where('booking_id', $booking->id)->count());
        $this->assertSame('booking_not_active', $this->code(fn () => app(BookingService::class)->cancel($booking)));
        $this->assertSame('none', $this->code(fn () => app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null)));
    }

    public function test_reschedule_moves_the_time_keeps_the_price_and_frees_the_old_time(): void
    {
        [$booking, $store, $service, $staff] = $this->booked();
        $service->forceFill(['price_minor' => 25000])->save(); // a later price change does not touch this booking (API-8)

        $made = app(HoldService::class)->hold($store, [], null, $this->local($store, '2026-10-05 10:30'), 'venue', null, [], $booking->fresh('lines'));
        $moved = app(BookingService::class)->reschedule($booking, $made['hold']->public_id, $made['token']);

        $this->assertSame('2026-10-05 07:30', $moved->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame(10000, $moved->total_minor);
        $this->assertSame('2026-10-04 07:30', $moved->cancel_deadline_at->utc()->format('Y-m-d H:i'));
        $cells = DB::table('booking_slot_cells')->where('booking_id', $booking->id)->orderBy('cell_at')->pluck('cell_at')->map(fn ($c) => CarbonImmutable::parse((string) $c, 'UTC')->format('H:i'))->all();
        $this->assertSame('07:30', $cells[0]);
        $this->assertSame('08:25', end($cells));
        $this->assertCount(12, $cells);
        $this->assertSame(0, DB::table('booking_slot_cells')->whereNotNull('hold_id')->count());
        // the old 10:00 start is free again for others
        $this->assertSame('none', $this->code(fn () => app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 09:00'), 'venue', null)));
    }

    public function test_reschedule_to_another_staff_member_moves_the_cells(): void
    {
        [$booking, $store, $service, $maha] = $this->booked();
        $sara = $this->staffMember($store, [$service], 'Sara', 1);
        $made = app(HoldService::class)->hold($store, [], $sara->id, $this->local($store, '2026-10-05 10:00'), 'venue', null, [], $booking->fresh('lines'));
        $moved = app(BookingService::class)->reschedule($booking, $made['hold']->public_id, $made['token']);

        $this->assertSame($sara->id, $moved->staff_id);
        $this->assertSame(12, DB::table('booking_slot_cells')->where('booking_id', $booking->id)->where('staff_id', $sara->id)->count());
        $this->assertSame(0, DB::table('booking_slot_cells')->where('staff_id', $maha->id)->count());
    }

    public function test_reschedule_and_free_changes_end_at_the_deadline(): void
    {
        [$booking, $store] = $this->booked();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 07:00:01', 'UTC')); // 1 s after the 24 h deadline

        $this->assertSame('deadline_passed', $this->code(fn () => app(HoldService::class)->hold($store, [], null, $this->local($store, '2026-10-05 12:00'), 'venue', null, [], $booking->fresh('lines'))));
        $this->assertFalse($booking->fresh()->beforeDeadline());
    }

    public function test_a_reschedule_hold_only_works_for_its_own_booking(): void
    {
        [$booking, $store, $service, $staff] = $this->booked();
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 13:00'), 'venue', null); // a plain hold

        $this->assertSame('hold_not_found', $this->code(fn () => app(BookingService::class)->reschedule($booking, $made['hold']->public_id, $made['token'])));
    }

    public function test_a_cancelled_booking_cannot_be_rescheduled(): void
    {
        [$booking, $store] = $this->booked();
        app(BookingService::class)->cancel($booking);

        $this->assertSame('booking_not_active', $this->code(fn () => app(HoldService::class)->hold($store, [], null, $this->local($store, '2026-10-05 12:00'), 'venue', null, [], $booking->fresh('lines'))));
    }

    public function test_complete_and_no_show_only_from_confirmed(): void
    {
        [$booking] = $this->booked();
        app(BookingService::class)->complete($booking);
        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertSame('booking_not_active', $this->code(fn () => app(BookingService::class)->noShow($booking)));
    }

    public function test_a_no_show_records_the_fee_only_when_the_store_turned_it_on(): void
    {
        [$a] = $this->booked();
        app(BookingService::class)->noShow($a);
        $this->assertSame(0, $a->fresh()->no_show_fee_minor); // D8: off by default

        [$b] = $this->booked(settings: ['booking_no_show_fee' => true, 'booking_no_show_fee_minor' => 5000]);
        app(BookingService::class)->noShow($b);
        $this->assertSame('no_show', $b->fresh()->status);
        $this->assertSame(5000, $b->fresh()->no_show_fee_minor);
    }

    public function test_several_services_run_back_to_back(): void
    {
        [$store, $cut, $staff] = $this->readyStore();
        $wash = $this->service($store, ['name' => 'Wash', 'duration_min' => 15, 'price_minor' => 2000]);
        $staff->services()->syncWithPivotValues([$cut->id, $wash->id], ['store_id' => $store->id, 'account_id' => $store->account_id]);
        $made = app(HoldService::class)->hold($store, [$wash->id, $cut->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'N', 'phone' => '1']);

        $this->assertSame(75, $booking->duration_min);
        $this->assertSame(12000, $booking->total_minor);
        $this->assertSame(['Wash', 'Haircut'], $booking->lines->pluck('name')->all());
        $this->assertSame('2026-10-05 08:15', $booking->ends_at->utc()->format('Y-m-d H:i'));
    }
}
