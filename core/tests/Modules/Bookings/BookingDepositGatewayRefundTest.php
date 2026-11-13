<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings;

use App\Models\StoreOption;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\DepositRefunds;
use Juwri\Modules\Bookings\Services\DepositService;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * P3-14 (lane C) — a booking deposit is refunded THROUGH the store's gateway when the store refunds deposits (D7/D8)
 * and switched automatic refunds on (owner decision 2026-09-27): Stub (armed) and Stripe (sandbox fake). Capped by what
 * the deposit payment took, at most once per booking (state + the payment's refund ledger), never on another store's
 * payment; a late payment refund (API-28) goes back the same way with deposit refunds off. Without automatic refunds
 * nothing is sent (tracked manual refund, as before).
 */
class BookingDepositGatewayRefundTest extends TestCase
{
    use BookingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC'));
        config(['app.e2e_stubs' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Booking, 1: Store} a 30 SAR deposit booking, paid on $gateway (settled like a real return) */
    private function paidBooking(string $gateway = 'stub', array $settings = ['booking_deposit_refunds' => true], bool $auto = true, string $paymentId = 'pay_1'): array
    {
        [$store, $service, $staff] = $this->readyStore(settings: $settings, serviceAttrs: ['deposit_type' => 'amount', 'deposit_value' => 3000]);
        if ($auto) {
            StoreOption::updateStoreOption($store->id, 'auto_refunds_enabled', '1');
        }
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'Noura', 'phone' => '1']);
        $txn = app(DepositService::class)->start($booking, $gateway);
        Settlement::settleForBuyer($txn->fresh(), $gateway, $paymentId, claim: true);
        $this->assertSame('paid', $booking->fresh()->deposit_status);

        return [$booking->fresh(), $store];
    }

    private function txn(Booking $booking): Transaction
    {
        return Transaction::query()->findOrFail(($booking->fresh() ?? $booking)->deposit_transaction_id);
    }

    /** @return list<array<string, mixed>> */
    private function gatewayRefunds(Booking $booking): array
    {
        return array_values(array_filter((array) json_decode((string) json_encode($this->txn($booking)->details?->gateway_refunds ?? []), true)));
    }

    private function merchantBalance(Store $store): float
    {
        return LedgerService::balanceFor(LedgerService::accountFor('merchant', (int) $store->user_id, 'merchant', 'SAR'));
    }

    public function test_cancel_before_the_deadline_refunds_the_deposit_through_the_stub_gateway_once(): void
    {
        [$booking, $store] = $this->paidBooking();
        $this->assertSame(Transaction::STATUS_SUCCESS, $this->txn($booking)->status);

        $this->assertSame('refunded', app(BookingService::class)->cancel($booking));
        $refunds = $this->gatewayRefunds($booking);
        $this->assertCount(1, $refunds);
        $this->assertSame(['ok', 3000, 'SAR'], [$refunds[0]['status'], $refunds[0]['amount_minor'], $refunds[0]['currency']]);
        $this->assertStringStartsWith('stub-refund-', (string) $refunds[0]['reference']);
        $this->assertTrue(DepositRefunds::wentBack($booking->fresh()));
        $this->assertSame('refunded', $booking->fresh()->deposit_status);
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($store), 0.001);

        // again: the booking is no longer active, a direct refund finds nothing to refund — nothing goes out twice
        $this->assertFalse(app(DepositService::class)->refund($booking->fresh()));
        try {
            app(BookingService::class)->cancel($booking->fresh());
            $this->fail('a cancelled booking cannot be cancelled again');
        } catch (BookingException $e) {
            $this->assertSame('booking_not_active', $e->errorCode);
        }
        $this->assertCount(1, $this->gatewayRefunds($booking));
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($store), 0.001);
    }

    public function test_stripe_receives_one_refund_of_the_deposit_on_its_payment_intent(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_dep_1', 'status' => 'succeeded'])]);
        [$booking, $store] = $this->paidBooking('stripe', paymentId: 'pi_dep_1');
        StoreSettings::set((int) $store->id, 'restaurant_stripe_secret_key', 'sk_test_x');

        $this->assertSame('refunded', app(BookingService::class)->cancel($booking));
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/v1/refunds')
            && $r['payment_intent'] === 'pi_dep_1' && (int) $r['amount'] === 3000);
        $this->assertSame('re_dep_1', $this->gatewayRefunds($booking)[0]['reference']);
        $this->assertTrue(DepositRefunds::wentBack($booking->fresh()));
    }

    public function test_a_stripe_refusal_keeps_a_tracked_manual_refund_and_moves_nothing_twice(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'charge_already_refunded']], 400)]);
        [$booking] = $this->paidBooking('stripe', paymentId: 'pi_dep_2');

        $this->assertSame('refunded', app(BookingService::class)->cancel($booking)); // recorded for the owner to finish
        $this->assertFalse(DepositRefunds::wentBack($booking->fresh()));
        $this->assertSame('failed', $this->gatewayRefunds($booking)[0]['status']);
        $this->assertSame('refunded', $booking->fresh()->deposit_status);
    }

    public function test_without_automatic_refunds_nothing_is_sent_to_the_gateway(): void
    {
        Http::fake();
        [$booking, $store] = $this->paidBooking('stripe', auto: false, paymentId: 'pi_dep_3');

        $this->assertSame('refunded', app(BookingService::class)->cancel($booking));
        Http::assertNothingSent();
        $this->assertSame([], $this->gatewayRefunds($booking));
        $this->assertSame('refunded', $booking->fresh()->deposit_status);
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($store), 0.001);
    }

    public function test_the_refund_is_capped_by_what_the_deposit_payment_took(): void
    {
        [$booking, $store] = $this->paidBooking();
        // the payment took less than the booking's deposit (e.g. a rounded charge): only that goes back
        $this->txn($booking)->forceFill(['amount' => 20.0, 'base_amount' => 20.0])->save();
        Transaction::query()->whereKey($booking->deposit_transaction_id)->update(['amount_minor' => 2000]);

        $this->assertSame('refunded', app(BookingService::class)->cancel($booking));
        $this->assertSame(2000, $this->gatewayRefunds($booking)[0]['amount_minor']);
        $this->assertEqualsWithDelta(10.0, $this->merchantBalance($store), 0.001, 'the ledger reverses only what went back');
    }

    public function test_deposit_refunds_off_keep_the_deposit_and_send_nothing(): void
    {
        [$booking, $store] = $this->paidBooking(settings: []); // D7 default: off

        $this->assertSame('kept', app(BookingService::class)->cancel($booking));
        $this->assertSame([], $this->gatewayRefunds($booking));
        $this->assertSame('kept', $booking->fresh()->deposit_status);
        $this->assertEqualsWithDelta(30.0, $this->merchantBalance($store), 0.001);
    }

    public function test_a_late_deposit_goes_back_through_the_gateway_with_deposit_refunds_off(): void
    {
        [$store, $service, $staff] = $this->readyStore(serviceAttrs: ['deposit_type' => 'amount', 'deposit_value' => 3000]);
        StoreOption::updateStoreOption($store->id, 'auto_refunds_enabled', '1');
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'Noura', 'phone' => '1']);
        $txn = app(DepositService::class)->start($booking, 'stub');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:20:00', 'UTC')); // after the 15-minute window
        Settlement::settleForBuyer($txn->fresh(), 'stub', 'pay_late', claim: true);
        $this->assertSame('received_late', $booking->fresh()->deposit_status);

        $this->assertTrue(app(DepositService::class)->refund($booking->fresh(), 'late_payment')); // API-28: outside D7
        $this->assertSame('ok', $this->gatewayRefunds($booking)[0]['status']);
        $this->assertSame('refunded', $booking->fresh()->deposit_status);
        $this->assertFalse(app(DepositService::class)->refund($booking->fresh(), 'late_payment'));
        $this->assertCount(1, $this->gatewayRefunds($booking));
    }

    public function test_a_refusal_before_the_gateway_rolls_the_refund_back(): void
    {
        [$booking, $store] = $this->paidBooking();
        // the payment is not settled any more (e.g. reversed by the provider): refused before any call, nothing recorded
        Transaction::query()->whereKey($booking->deposit_transaction_id)->update(['status' => Transaction::STATUS_PENDING]);

        try {
            app(DepositService::class)->refund($booking);
            $this->fail('a refusal must throw');
        } catch (BookingException $e) {
            $this->assertSame('refund_refused', $e->errorCode);
        }
        $this->assertSame('paid', $booking->fresh()->deposit_status);
        $this->assertSame([], $this->gatewayRefunds($booking));
        $this->assertEqualsWithDelta(30.0, $this->merchantBalance($store), 0.001);
    }

    public function test_a_booking_pointing_at_another_stores_payment_never_refunds_it(): void
    {
        [$mine] = $this->paidBooking(paymentId: 'pay_mine');
        [$theirs] = $this->paidBooking(paymentId: 'pay_theirs');
        // tampered row: my booking names the other store's deposit payment
        Booking::query()->whereKey($mine->id)->update(['deposit_transaction_id' => $theirs->deposit_transaction_id]);

        app(DepositService::class)->refund($mine->fresh());
        $this->assertSame([], $this->gatewayRefunds($theirs), 'the other store\'s payment is untouched');
        $this->assertFalse(DepositRefunds::wentBack($mine->fresh()));
    }
}
