<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Models\Reservation;
use App\Models\StoreOption;
use App\Services\ReservationDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\LedgerService;
use Mockery\MockInterface;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-336: refund() / confirmManualRefund() — money logic in ReservationDepositService is done;
 * these tests pin the refund lifecycle on the gateway layer, the row-lock double-cancel guard,
 * policy capping, and the stale-in-memory model guard that refund() relies on. No real
 * network: the {@see Juwri\Modules\Payments\Contracts\Gateways} contract is bound to a partial
 * Mockery double so the production adapter is bypassed and the test owns the response shape.
 */
class ReservationDepositRefundTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(array $opts = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (array_merge([
            'reservation_deposit_enabled' => '1', 'reservation_deposit_amount' => 10,
            'reservation_deposit_per' => 'guest', 'reservation_deposit_min_party' => 4,
            'reservation_deposit_refund' => 'full', 'currency_code' => 'USD',
        ], $opts) as $k => $v) {
            StoreOption::updateStoreOption($r->id, $k, $v);
        }

        return $r;
    }

    private function paidReservation(array $opts = []): array
    {
        $r = $this->storeFixture($opts);
        $res = Reservation::create([
            'store_id' => $r->id, 'name' => 'Guest', 'party_size' => 4,
            'reserved_at' => now()->addDay(), 'status' => 'pending',
        ]);
        $txn = ReservationDepositService::initiate($res, 'stripe');
        // markPaid() bypasses the gateway (the fulfiller would also flip transaction.status to
        // success; for our partial double, that branch is bypassed entirely so we do not need to
        // settle the row — but markPaid is the path the rest of the app uses after Fulfilment).
        ReservationDepositService::markPaid($res, $txn);

        return [$r, $res->fresh(), $txn];
    }

    /** Merchant balance for the store in the deposit's currency. */
    private function merchantBalance(Store $r): float
    {
        $account = LedgerService::accountFor('merchant', (int) $r->user_id, 'merchant', 'USD');

        return LedgerService::balanceFor($account);
    }

    /**
     * Bind a Gateways double whose every other method (decimals, refusal, ...) returns null
     * through shouldIgnoreMissing(). Tests that call refund() then add their own
     * shouldReceive('refundStorePayment') with the return they need.
     */
    private function fakeGateways(): MockInterface
    {
        return $this->mock(Gateways::class, function ($mock) {
            $mock->shouldIgnoreMissing();
        });
    }

    /** a. Provider automatic=true: gateway called once with (txn, 40.0, 'USD'); status refunded; balance 0. */
    public function test_gateway_automatic_refund_succeeds_and_books_the_reversal(): void
    {
        $gateways = $this->fakeGateways();
        [$r, $res, $txn] = $this->paidReservation(['reservation_deposit_refund' => 'full']);

        $gateways->shouldReceive('refundStorePayment')
            ->once()
            ->withArgs(function (Transaction $called, float $amount, ?string $currency) use ($txn) {
                return $called->is($txn) && abs($amount - 40.0) < 0.001 && $currency === 'USD';
            })
            ->andReturn(['automatic' => true, 'gateway' => 'stripe', 'reference' => 're_1', 'message' => null]);

        $refunded = ReservationDepositService::refund($res);
        $this->assertEqualsWithDelta(40.0, $refunded, 0.001);
        $this->assertSame('refunded', $res->fresh()->deposit_status);
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($r), 0.001);
    }

    /** b. Provider cannot refund automatically: status refund_due, balance untouched; manual confirm closes it. */
    public function test_gateway_manual_then_confirm_marks_refunded_and_reverses_the_ledger(): void
    {
        $gateways = $this->fakeGateways();
        [$r, $res] = $this->paidReservation(['reservation_deposit_refund' => 'full']);

        $gateways->shouldReceive('refundStorePayment')
            ->once()
            ->andReturn(['automatic' => false, 'gateway' => 'stripe', 'reference' => null, 'message' => 'Provider down']);

        $this->assertEqualsWithDelta(0.0, ReservationDepositService::refund($res), 0.001);
        $this->assertSame('refund_due', $res->fresh()->deposit_status);
        $this->assertEqualsWithDelta(40.0, $this->merchantBalance($r), 0.001, 'no ledger legs while the refund is pending');

        $booked = ReservationDepositService::confirmManualRefund($res->fresh());
        $this->assertEqualsWithDelta(40.0, $booked, 0.001);
        $this->assertSame('refunded', $res->fresh()->deposit_status);
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($r), 0.001);

        // Second call after the manual confirm: nothing to do (already refunded, row-locked).
        $this->assertEqualsWithDelta(0.0, ReservationDepositService::confirmManualRefund($res->fresh()), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($r), 0.001);
    }

    /** c. Provider refuses before any call: deposit stays paid, balance untouched, gateway result recorded. */
    public function test_gateway_refused_leaves_the_deposit_paid_and_does_not_move_money(): void
    {
        $gateways = $this->fakeGateways();
        [$r, $res] = $this->paidReservation(['reservation_deposit_refund' => 'full']);

        $gateways->shouldReceive('refundStorePayment')
            ->once()
            ->andReturn(['refused' => true, 'automatic' => false, 'message' => 'x']);

        $this->assertEqualsWithDelta(0.0, ReservationDepositService::refund($res), 0.001);
        $this->assertSame('paid', $res->fresh()->deposit_status);
        $this->assertEqualsWithDelta(40.0, $this->merchantBalance($r), 0.001);
    }

    /** d. Double cancel: row lock + deposit_status check refund once; second call is a no-op. */
    public function test_double_cancel_refunds_once_and_the_second_call_returns_zero(): void
    {
        $gateways = $this->fakeGateways();
        [$r, $res] = $this->paidReservation(['reservation_deposit_refund' => 'full']);

        $gateways->shouldReceive('refundStorePayment')->once()->andReturn([
            'automatic' => true, 'gateway' => 'stripe', 'reference' => 're_1', 'message' => null,
        ]);

        $first = ReservationDepositService::refund($res);
        $second = ReservationDepositService::refund($res->fresh());

        $this->assertEqualsWithDelta(40.0, $first, 0.001);
        $this->assertEqualsWithDelta(0.0, $second, 0.001);
        $this->assertSame('refunded', $res->fresh()->deposit_status);
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($r), 0.001);
    }

    /** e. Partial policy: pct >100 is clamped to the deposit; pct = 50 refunds half. */
    public function test_partial_policy_caps_at_the_deposit_and_refunds_half_at_50_percent(): void
    {
        $gateways = $this->fakeGateways();

        // pct = 150: should be clamped to 100% of the 40 deposit.
        [$r, $res] = $this->paidReservation([
            'reservation_deposit_refund' => 'partial', 'reservation_deposit_refund_pct' => 150,
        ]);
        $gateways->shouldReceive('refundStorePayment')->once()->withArgs(function (Transaction $called, float $amount) {
            return abs($amount - 40.0) < 0.001;
        })->andReturn(['automatic' => true, 'gateway' => 'stripe', 'reference' => 're_a', 'message' => null]);

        $this->assertEqualsWithDelta(40.0, ReservationDepositService::refund($res), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($r), 0.001);

        // pct = 50: refunds 20.
        [$r2, $res2] = $this->paidReservation([
            'reservation_deposit_refund' => 'partial', 'reservation_deposit_refund_pct' => 50,
        ]);
        $gateways->shouldReceive('refundStorePayment')->once()->withArgs(function (Transaction $called, float $amount) {
            return abs($amount - 20.0) < 0.001;
        })->andReturn(['automatic' => true, 'gateway' => 'stripe', 'reference' => 're_b', 'message' => null]);

        $this->assertEqualsWithDelta(20.0, ReservationDepositService::refund($res2), 0.001);
        $this->assertEqualsWithDelta(20.0, $this->merchantBalance($r2), 0.001);
    }

    /**
     * f. Stale in-memory model: the caller hands refund() a model that still says deposit_status='paid'
     * in memory, but the row was already refunded by an earlier call. The row-locked re-read wins
     * and the gateway is NOT called.
     */
    public function test_stale_in_memory_model_is_corrected_by_the_row_locked_re_read(): void
    {
        $gateways = $this->fakeGateways();
        [$r, $res] = $this->paidReservation(['reservation_deposit_refund' => 'full']);

        // First refund: real refund path.
        $gateways->shouldReceive('refundStorePayment')->once()->andReturn([
            'automatic' => true, 'gateway' => 'stripe', 'reference' => 're_1', 'message' => null,
        ]);
        ReservationDepositService::refund($res);

        // Build a stale in-memory copy: same row id, but the attribute still says 'paid' — as a
        // controller in production that holds a model and reuses it across requests might see.
        // The row in DB is now 'refunded'; only the in-memory attribute is wrong.
        $stale = Reservation::find($res->id);
        $stale->setRawAttributes(array_merge($stale->getAttributes(), ['deposit_status' => 'paid']));

        $second = ReservationDepositService::refund($stale);
        $this->assertEqualsWithDelta(0.0, $second, 0.001);
        // Gateway was only invoked once (the first refund); the stale call found 'refunded' in DB.
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($r), 0.001);
    }
}
