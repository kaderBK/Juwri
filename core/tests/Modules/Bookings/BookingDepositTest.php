<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\DepositService;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Bookings\Services\ManageLink;
use Juwri\Modules\Bookings\Services\SlotEngine;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\LedgerService;
use Juwri\Modules\Payments\Support\Fulfilment;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * P3-10 deposit / refund matrix (D7, D8, API-10/19/21/27/28): amount and percent deposits, the 15-minute payment
 * window, payment through the store-payment choke point, refunds only with the switch on and before the deadline,
 * kept after it, late payments never revive a booking and are always refundable, accept re-reserves when free.
 */
class BookingDepositTest extends TestCase
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

    /** @return array{0: Booking, 1: Store, 2: BookableService, 3: mixed} */
    private function pending(array $settings = [], array $serviceAttrs = ['deposit_type' => 'amount', 'deposit_value' => 3000], string $at = '2026-10-05 10:00'): array
    {
        [$store, $service, $staff] = $this->readyStore(settings: $settings, serviceAttrs: $serviceAttrs);
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, $at), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'Noura', 'phone' => '1']);

        return [$booking, $store, $service, $staff];
    }

    private function pay(Booking $booking): void
    {
        $txn = app(DepositService::class)->start($booking, 'stripe');
        Fulfilment::fulfil($txn->fresh());
    }

    private function merchantBalance(Store $store): float
    {
        return LedgerService::balanceFor(LedgerService::accountFor('merchant', (int) $store->user_id, 'merchant', 'SAR'));
    }

    public function test_deposit_amounts_fixed_and_percent_never_above_the_price(): void
    {
        $store = $this->servicesStore();
        $this->assertSame(3000, $this->service($store, ['deposit_type' => 'amount', 'deposit_value' => 3000])->depositMinor());
        $this->assertSame(2500, $this->service($store, ['deposit_type' => 'percent', 'deposit_value' => 25])->depositMinor());
        $this->assertSame(10000, $this->service($store, ['deposit_type' => 'amount', 'deposit_value' => 99999])->depositMinor());
        $this->assertSame(0, $this->service($store, ['deposit_type' => 'none', 'deposit_value' => 5000])->depositMinor());
        $this->assertSame(3300, $this->service($store, ['price_minor' => 9999, 'deposit_type' => 'percent', 'deposit_value' => 33])->depositMinor()); // half-up
    }

    public function test_a_deposit_booking_waits_for_payment_then_confirms_and_the_ledger_holds_it(): void
    {
        [$booking, $store] = $this->pending();
        $this->assertSame('pending_payment', $booking->status);
        $this->assertSame('pending', $booking->deposit_status);
        $this->assertSame('2026-10-04 03:15', $booking->payment_expires_at->utc()->format('Y-m-d H:i'));
        $this->assertSame(12, DB::table('booking_slot_cells')->where('booking_id', $booking->id)->where('expires_at', '>', '2026-10-04 03:00:00')->count());

        $this->pay($booking);
        $fresh = $booking->fresh();
        $this->assertSame('confirmed', $fresh->status);
        $this->assertSame('paid', $fresh->deposit_status);
        $this->assertNull($fresh->payment_expires_at);
        $this->assertSame(12, DB::table('booking_slot_cells')->where('booking_id', $booking->id)->whereNull('expires_at')->count());
        $this->assertEqualsWithDelta(30.0, $this->merchantBalance($store), 0.001);
    }

    public function test_a_replayed_confirmation_credits_once(): void
    {
        [$booking, $store] = $this->pending();
        $txn = app(DepositService::class)->start($booking, 'stripe');
        app(DepositService::class)->markPaid($txn);
        app(DepositService::class)->markPaid($txn);

        $this->assertEqualsWithDelta(30.0, $this->merchantBalance($store), 0.001);
    }

    public function test_an_unpaid_booking_is_cancelled_when_the_window_ends_and_its_time_is_freed(): void
    {
        [$booking, $store, $service, $staff] = $this->pending();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:15:01', 'UTC'));
        app(HoldService::class)->purgeExpired();

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame('system', $booking->fresh()->cancelled_by);
        $this->assertSame(0, DB::table('booking_slot_cells')->where('booking_id', $booking->id)->count());
        $this->expectException(BookingException::class);
        app(DepositService::class)->start($booking->fresh(), 'stripe'); // payment_window_closed
    }

    public function test_a_payment_after_the_window_is_received_late_and_never_revives_the_booking(): void
    {
        [$booking, $store, $service, $staff] = $this->pending();
        $txn = app(DepositService::class)->start($booking, 'stripe');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:20:00', 'UTC'));
        // someone else takes the freed time first
        $other = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $this->assertNotNull($other['hold']->id);

        Fulfilment::fulfil($txn->fresh());
        $fresh = $booking->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('received_late', $fresh->deposit_status);
        $this->assertEqualsWithDelta(30.0, $this->merchantBalance($store), 0.001); // the money did arrive
    }

    public function test_owner_accepting_a_late_payment_re_reserves_a_free_time_or_waits_for_approval(): void
    {
        [$a, $store] = $this->pending();
        $txn = app(DepositService::class)->start($a, 'stripe');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:20:00', 'UTC'));
        app(DepositService::class)->markPaid($txn);
        $this->assertSame('confirmed', app(DepositService::class)->acceptLate($a->fresh(), app(HoldService::class), app(SlotEngine::class)));
        $this->assertSame('paid', $a->fresh()->deposit_status);
        $this->assertSame(12, DB::table('booking_slot_cells')->where('booking_id', $a->id)->count());

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC'));
        [$b, $store2, $service2, $staff2] = $this->pending();
        $txn2 = app(DepositService::class)->start($b, 'stripe');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:20:00', 'UTC'));
        app(HoldService::class)->hold($store2, [$service2->id], $staff2->id, $this->local($store2, '2026-10-05 10:00'), 'venue', null); // time taken
        app(DepositService::class)->markPaid($txn2);
        $this->assertSame('pending_approval', app(DepositService::class)->acceptLate($b->fresh(), app(HoldService::class), app(SlotEngine::class)));
        $this->assertSame('pending_approval', $b->fresh()->status);
    }

    public function test_a_late_payment_is_always_refundable_even_with_deposit_refunds_off(): void
    {
        [$booking, $store] = $this->pending(['booking_deposit_refunds' => false]);
        $txn = app(DepositService::class)->start($booking, 'stripe');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:20:00', 'UTC'));
        app(DepositService::class)->markPaid($txn);

        $this->assertTrue(app(DepositService::class)->refund($booking->fresh(), 'late_payment'));
        $this->assertSame('refunded', $booking->fresh()->deposit_status);
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($store), 0.001);
    }

    public function test_cancel_before_the_deadline_refunds_only_when_the_store_turned_refunds_on(): void
    {
        [$on, $storeOn] = $this->pending(['booking_deposit_refunds' => true]);
        $this->pay($on);
        $this->assertSame('refunded', app(BookingService::class)->cancel($on->fresh()));
        $this->assertSame('refunded', $on->fresh()->deposit_status);
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($storeOn), 0.001);

        [$off, $storeOff] = $this->pending(); // D7 default: off
        $this->pay($off);
        $this->assertSame('kept', app(BookingService::class)->cancel($off->fresh()));
        $this->assertSame('kept', $off->fresh()->deposit_status);
        $this->assertEqualsWithDelta(30.0, $this->merchantBalance($storeOff), 0.001);
    }

    public function test_cancel_after_the_deadline_keeps_the_deposit_even_with_refunds_on(): void
    {
        [$booking, $store] = $this->pending(['booking_deposit_refunds' => true]);
        $this->pay($booking);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 08:00:00', 'UTC')); // deadline was 07:00 UTC

        $this->assertSame('kept', app(BookingService::class)->cancel($booking->fresh()));
        $this->assertSame('kept', $booking->fresh()->deposit_status);
    }

    public function test_a_no_show_keeps_a_paid_deposit(): void
    {
        [$booking] = $this->pending(['booking_deposit_refunds' => true]);
        $this->pay($booking);
        app(BookingService::class)->noShow($booking->fresh());

        $this->assertSame('kept', $booking->fresh()->deposit_status);
    }

    public function test_reschedule_carries_the_paid_deposit_over_and_moves_no_money(): void
    {
        [$booking, $store] = $this->pending();
        $this->pay($booking);
        $made = app(HoldService::class)->hold($store, [], null, $this->local($store, '2026-10-05 14:00'), 'venue', null, [], $booking->fresh('lines'));
        $moved = app(BookingService::class)->reschedule($booking->fresh(), $made['hold']->public_id, $made['token']);

        $this->assertSame('paid', $moved->deposit_status);
        $this->assertSame(3000, $moved->deposit_minor);
        $this->assertEqualsWithDelta(30.0, $this->merchantBalance($store), 0.001);
        $this->assertSame(1, Transaction::query()->where('transaction_method', 'booking_deposit')->count());
    }

    public function test_a_pending_deposit_booking_cannot_be_rescheduled(): void
    {
        [$booking, $store] = $this->pending();
        $this->expectException(BookingException::class);
        app(HoldService::class)->hold($store, [], null, $this->local($store, '2026-10-05 14:00'), 'venue', null, [], $booking->fresh('lines'));
    }

    public function test_the_gateway_return_settles_the_deposit_and_sends_the_customer_to_the_booking_page(): void
    {
        [$booking, $store] = $this->pending();
        $txn = app(DepositService::class)->start($booking, 'stub');
        $manage = ManageLink::url($booking->fresh(), $store);
        $this->assertSame($manage, $txn->details->return_url);
        $this->assertTrue(Settlement::isStorePayment($txn)); // charged on the STORE's gateway
        $this->assertSame($manage, Settlement::cancelUrl($txn));

        $response = Settlement::settleForBuyer($txn->fresh(), 'stub', 'stub-'.$txn->id, claim: true);
        $this->assertNotNull($response);
        $this->assertSame($manage, $response->headers->get('Location'));
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_a_return_url_outside_the_store_is_never_followed(): void
    {
        [$booking, $store] = $this->pending();
        $txn = app(DepositService::class)->start($booking, 'stub');
        $txn->forceFill(['details' => ['return_url' => 'https://evil.example/phish']])->save();

        $this->assertSame(route('publicView', $store->slug), Settlement::cancelUrl($txn->fresh()));
    }
}
