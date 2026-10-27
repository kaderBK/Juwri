<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings;

use App\Models\StoreOption;
use App\Services\DashboardAttentionService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Contracts\BookingApi;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingDepositRefund;
use Juwri\Modules\Bookings\Models\BookingHold;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\DepositService;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Bookings\Services\ManageLink;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\LedgerService;
use Juwri\Modules\Storefront\Services\Api\StorefrontBookingView;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * Lane C bug fix: when the gateway layer refused a deposit refund during BookingService::cancel, the booking stayed
 * cancelled with the deposit paid and nobody was told. Now the refused refund is recorded as pending (once per
 * booking), the customer is told it is being processed (SMS once, manage page, API policy note), the owner sees it on
 * the dashboard attention list and the bookings page, and "Retry refund" sends it through the same guarded refund
 * (booking row lock, deposit state, DepositRefunds cap, RefundGuard) — at most once. Also API-29 for bookings: the
 * deposit-not-paid message after the customer's own hold release, only when the store turned it on.
 */
class BookingDepositRefundPendingTest extends TestCase
{
    use BookingFixtures;
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string, 2: int|null, 3: bool}> */
    private array $sms = [];

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
        config(['app.e2e_stubs' => true]);
        $this->sms = [];
        $this->mock(MessageQueue::class, function ($mock) {
            $mock->shouldReceive('sms')->andReturnUsing(function (string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false) {
                $this->sms[] = [$to, $message, $storeId, $marketing];
            });
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Booking, 1: Store} a 30 SAR deposit booking paid on the armed Stub, deposit refunds on */
    private function paidBooking(string $paymentId = 'pay_1'): array
    {
        [$store, $service, $staff] = $this->readyStore(settings: ['booking_deposit_refunds' => true], serviceAttrs: ['deposit_type' => 'amount', 'deposit_value' => 3000]);
        StoreOption::updateStoreOption($store->id, 'auto_refunds_enabled', '1');
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'Noura', 'phone' => '+966551234567']);
        $txn = app(DepositService::class)->start($booking, 'stub');
        Settlement::settleForBuyer($txn->fresh(), 'stub', $paymentId, claim: true);
        $this->assertSame('paid', $booking->fresh()->deposit_status);

        return [$booking->fresh(), $store];
    }

    /** The payment is not settled any more (e.g. reversed by the provider): the gateway layer refuses the refund. */
    private function refuseRefunds(Booking $booking): void
    {
        Transaction::query()->whereKey($booking->deposit_transaction_id)->update(['status' => Transaction::STATUS_PENDING]);
    }

    private function allowRefunds(Booking $booking): void
    {
        Transaction::query()->whereKey($booking->deposit_transaction_id)->update(['status' => Transaction::STATUS_SUCCESS]);
    }

    /** @return list<array<string, mixed>> */
    private function gatewayRefunds(Booking $booking): array
    {
        $txn = Transaction::query()->findOrFail($booking->deposit_transaction_id);

        return array_values(array_filter((array) json_decode((string) json_encode($txn->details?->gateway_refunds ?? []), true)));
    }

    private function merchantBalance(Store $store): float
    {
        return LedgerService::balanceFor(LedgerService::accountFor('merchant', (int) $store->user_id, 'merchant', 'SAR'));
    }

    private function pending(Booking $booking): ?BookingDepositRefund
    {
        return BookingDepositRefund::query()->where('booking_id', $booking->id)->first();
    }

    public function test_a_refused_refund_on_cancel_is_recorded_as_pending_and_the_customer_is_told_once(): void
    {
        [$booking, $store] = $this->paidBooking();
        $this->refuseRefunds($booking);

        $this->assertSame('refund_pending', app(BookingService::class)->cancel($booking));
        $fresh = $booking->fresh();
        $this->assertSame('cancelled', $fresh->status, 'the booking is cancelled and its time freed');
        $this->assertSame('paid', $fresh->deposit_status, 'no money moved: the deposit is still paid');
        $this->assertSame(0, DB::table('booking_slot_cells')->where('booking_id', $booking->id)->count());
        $row = $this->pending($booking);
        $this->assertNotNull($row);
        $this->assertSame(['pending', 1, 3000, 'SAR', (int) $store->id], [$row->status, $row->attempts, $row->amount_minor, $row->currency, $row->store_id]);
        $this->assertNotSame('', (string) $row->last_error);
        $this->assertSame([], $this->gatewayRefunds($booking));
        $this->assertEqualsWithDelta(30.0, $this->merchantBalance($store), 0.001);

        $this->assertCount(1, $this->sms);
        $this->assertSame('+966551234567', $this->sms[0][0]);
        $this->assertStringContainsString($booking->number, $this->sms[0][1]);
        $this->assertStringContainsString('being processed', $this->sms[0][1]);
        $this->assertFalse($this->sms[0][3], 'transactional, not marketing');
        $this->assertTrue(DB::table('activity_logs')->where('store_id', $store->id)->where('action', 'booking.deposit_refund_pending')->exists());
    }

    public function test_retry_while_still_refused_counts_the_attempt_and_moves_nothing(): void
    {
        [$booking, $store] = $this->paidBooking();
        $this->refuseRefunds($booking);
        app(BookingService::class)->cancel($booking);

        $result = app(DepositService::class)->retryRefund($booking->fresh());
        $this->assertSame('pending', $result['outcome']);
        $this->assertNotNull($result['message']);
        $this->assertSame(2, $this->pending($booking)->attempts);
        $this->assertSame('pending', $this->pending($booking)->status);
        $this->assertSame('paid', $booking->fresh()->deposit_status);
        $this->assertSame([], $this->gatewayRefunds($booking));
        $this->assertCount(1, $this->sms, 'the customer is told once, not on every retry');
        $this->assertEqualsWithDelta(30.0, $this->merchantBalance($store), 0.001);
    }

    public function test_a_successful_retry_refunds_exactly_once_and_closes_the_pending_refund(): void
    {
        [$booking, $store] = $this->paidBooking();
        $this->refuseRefunds($booking);
        app(BookingService::class)->cancel($booking);
        $this->allowRefunds($booking);

        $this->assertSame('refunded', app(DepositService::class)->retryRefund($booking->fresh())['outcome']);
        $this->assertSame('refunded', $booking->fresh()->deposit_status);
        $row = $this->pending($booking);
        $this->assertSame('done', $row->status);
        $this->assertNotNull($row->resolved_at);
        $refunds = $this->gatewayRefunds($booking);
        $this->assertCount(1, $refunds);
        $this->assertSame(['ok', 3000], [$refunds[0]['status'], $refunds[0]['amount_minor']]);
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($store), 0.001);

        // idempotent: a second click (or a second owner) finds nothing to refund
        $this->assertSame('nothing', app(DepositService::class)->retryRefund($booking->fresh())['outcome']);
        $this->assertFalse(app(DepositService::class)->refund($booking->fresh()));
        $this->assertCount(1, $this->gatewayRefunds($booking));
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($store), 0.001);
    }

    public function test_the_retry_is_capped_by_what_the_deposit_payment_took(): void
    {
        [$booking, $store] = $this->paidBooking();
        $this->refuseRefunds($booking);
        app(BookingService::class)->cancel($booking);
        $txn = Transaction::query()->findOrFail($booking->deposit_transaction_id);
        $txn->forceFill(['status' => Transaction::STATUS_SUCCESS, 'amount' => 20.0, 'base_amount' => 20.0])->save();
        Transaction::query()->whereKey($txn->id)->update(['amount_minor' => 2000]);

        $this->assertSame('refunded', app(DepositService::class)->retryRefund($booking->fresh())['outcome']);
        $this->assertSame(2000, $this->gatewayRefunds($booking)[0]['amount_minor']);
        $this->assertEqualsWithDelta(10.0, $this->merchantBalance($store), 0.001);
    }

    public function test_the_owner_sees_and_retries_the_refund_on_the_bookings_page_and_the_dashboard(): void
    {
        [$booking, $store] = $this->paidBooking();
        $this->refuseRefunds($booking);
        $owner = $store->user;
        $q = ['store_id' => $store->id];

        // the owner cancels: the message says the refund was refused and where to retry it
        $this->actingAs($owner)->from(route('bookings.owner.index', $q))
            ->post(route('bookings.owner.action', ['booking' => $booking->id] + $q), ['do' => 'cancel'])
            ->assertRedirect()->assertSessionHas('success', fn (string $m) => str_contains($m, 'refused'));
        $this->assertSame('pending', $this->pending($booking)->status);

        $page = $this->actingAs($owner)->get(route('bookings.owner.index', $q))->assertOk();
        $page->assertSee(___('Deposit refunds to retry'))->assertSee(___('Retry refund'))->assertSee($booking->number)->assertSee(___('Refund refused'));

        $attention = collect(DashboardAttentionService::for([(int) $store->id]))->keyBy('type');
        $this->assertSame(1, $attention['deposit_refunds']['count']);
        $this->assertSame('danger', $attention['deposit_refunds']['severity']);
        $this->assertStringContainsString('store_id='.$store->id, $attention['deposit_refunds']['url']);
        $this->assertSame(count(DashboardAttentionService::for([(int) $store->id])), DashboardAttentionService::countsByStore([(int) $store->id])[(int) $store->id]);

        // still refused: the owner is told why, nothing moves
        $this->actingAs($owner)->from(route('bookings.owner.index', $q))
            ->post(route('bookings.owner.action', ['booking' => $booking->id] + $q), ['do' => 'retry_refund'])
            ->assertRedirect()->assertSessionHas('success', fn (string $m) => str_contains($m, 'refused again'));

        $this->allowRefunds($booking);
        $this->actingAs($owner)->from(route('bookings.owner.index', $q))
            ->post(route('bookings.owner.action', ['booking' => $booking->id] + $q), ['do' => 'retry_refund'])
            ->assertRedirect()->assertSessionHas('success', ___('Deposit refunded to the customer\'s payment method.'));
        $this->assertSame('refunded', $booking->fresh()->deposit_status);
        $this->actingAs($owner)->get(route('bookings.owner.index', $q))->assertOk()->assertDontSee(___('Deposit refunds to retry'));
        $this->assertArrayNotHasKey('deposit_refunds', collect(DashboardAttentionService::for([(int) $store->id]))->keyBy('type')->all());

        // a double submit after it went through refunds nothing
        $this->actingAs($owner)->from(route('bookings.owner.index', $q))
            ->post(route('bookings.owner.action', ['booking' => $booking->id] + $q), ['do' => 'retry_refund'])
            ->assertSessionHas('success', ___('Nothing to refund.'));
        $this->assertCount(1, $this->gatewayRefunds($booking));
    }

    public function test_another_store_cannot_see_or_retry_the_pending_refund(): void
    {
        [$booking, $store] = $this->paidBooking('pay_mine');
        $this->refuseRefunds($booking);
        app(BookingService::class)->cancel($booking);
        [, $other] = $this->paidBooking('pay_theirs');
        $this->allowRefunds($booking);

        // the other owner names my booking under their own store: not found within their store, nothing refunded
        $this->actingAs($other->user)->post(route('bookings.owner.action', ['booking' => $booking->id, 'store_id' => $other->id]), ['do' => 'retry_refund'])->assertNotFound();
        // …or under mine: not their store
        $this->actingAs($other->user)->post(route('bookings.owner.action', ['booking' => $booking->id, 'store_id' => $store->id]), ['do' => 'retry_refund'])->assertForbidden();
        $this->assertSame('pending', $this->pending($booking)->status);
        $this->assertSame([], $this->gatewayRefunds($booking));
        $this->assertArrayNotHasKey('deposit_refunds', collect(DashboardAttentionService::for([(int) $other->id]))->keyBy('type')->all());
        $this->actingAs($other->user)->get(route('bookings.owner.index', ['store_id' => $other->id]))->assertOk()->assertDontSee($booking->number);
    }

    public function test_the_customer_sees_the_refund_is_being_processed(): void
    {
        [$booking, $store] = $this->paidBooking();
        $this->refuseRefunds($booking);
        $token = ManageLink::token($booking);

        $this->from(route('bookings.manage', ['slug' => $store->slug, 'token' => $token]))
            ->post(route('bookings.cancel', ['slug' => $store->slug, 'token' => $token]))
            ->assertSessionHas('success', ___('Your booking is cancelled. Your deposit refund is being processed.'));
        $this->get(route('bookings.manage', ['slug' => $store->slug, 'token' => $token]))->assertOk()
            ->assertSee('data-testid="refund-pending"', false)->assertSee(___('Your deposit refund is being processed.'));

        $note = app(StorefrontBookingView::class)->policyNote($store, $booking->fresh(), 'refund_pending');
        $this->assertStringContainsString('being processed', $note);
        $this->assertSame('paid', app(StorefrontBookingView::class)->payment($store, $booking->fresh())['status'], 'API: the money has not come back yet');

        // once refunded, the page shows the refund
        $this->allowRefunds($booking);
        app(DepositService::class)->retryRefund($booking->fresh());
        $this->get(route('bookings.manage', ['slug' => $store->slug, 'token' => $token]))->assertOk()
            ->assertDontSee('data-testid="refund-pending"', false)->assertSee(___('Deposit refunded'));
    }

    public function test_a_refund_that_goes_through_on_cancel_records_nothing_pending(): void
    {
        [$booking] = $this->paidBooking();

        $this->assertSame('refunded', app(BookingService::class)->cancel($booking));
        $this->assertNull($this->pending($booking));
        $this->assertSame([], $this->sms);
    }

    /** @return array{0: Store, 1: Booking, 2: BookingHold, 3: string} a booking waiting for its deposit and its (used) hold */
    private function pendingDepositBooking(): array
    {
        [$store, $service, $staff] = $this->readyStore(settings: ['holds_release_during_payment' => true], serviceAttrs: ['deposit_type' => 'amount', 'deposit_value' => 3000]);
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 10:00'), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'Noura', 'phone' => '+966551234567']);
        $this->assertSame('pending_payment', $booking->status);

        return [$store, $booking, $made['hold']->fresh(), $made['token']];
    }

    public function test_api29_the_customer_release_of_a_pending_deposit_booking_messages_only_when_the_store_turned_it_on(): void
    {
        [$store, $booking, $hold, $token] = $this->pendingDepositBooking();
        $cancelled = app(BookingApi::class)->releaseHold($store, $hold, $token);
        $this->assertSame('cancelled', $cancelled?->status);
        $this->assertSame([], $this->sms, 'off by default: nothing is sent');

        [$store, $booking, $hold, $token] = $this->pendingDepositBooking();
        StoreSettings::set((int) $store->id, 'notify_customer_pending_payment_cancelled', true);
        app(BookingApi::class)->releaseHold($store, $hold, $token);
        $this->assertCount(1, $this->sms);
        $this->assertSame('+966551234567', $this->sms[0][0]);
        $this->assertStringContainsString($booking->number, $this->sms[0][1]);
        $this->assertStringContainsString('not charged', $this->sms[0][1]);
        $this->assertSame((int) $store->id, $this->sms[0][2]);
        $this->assertFalse($this->sms[0][3]);

        // a plain (unbound) hold release cancels nothing and sends nothing
        [$store2, $service2, $staff2] = $this->readyStore(settings: ['notify_customer_pending_payment_cancelled' => true]);
        $plain = app(HoldService::class)->hold($store2, [$service2->id], $staff2->id, $this->local($store2, '2026-10-05 11:00'), 'venue', null);
        $this->assertNull(app(BookingApi::class)->releaseHold($store2, $plain['hold'], $plain['token']));
        $this->assertCount(1, $this->sms);
    }
}
