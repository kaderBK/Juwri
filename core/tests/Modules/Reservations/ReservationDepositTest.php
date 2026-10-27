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
use Juwri\Modules\Payments\Services\LedgerService;
use Juwri\Modules\Payments\Support\Fulfilment;
use Mockery\MockInterface;
use Tests\Support\TestPlans;
use Tests\TestCase;

class ReservationDepositTest extends TestCase
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

    private function reservation(Store $r, int $party = 4): Reservation
    {
        return Reservation::create([
            'store_id' => $r->id, 'name' => 'Guest', 'party_size' => $party,
            'reserved_at' => now()->addDay(), 'status' => 'pending',
        ]);
    }

    /**
     * P6-336: refund() reaches into Gateways::refundStorePayment — bind a partial double so the
     * production adapter is bypassed (no live SDK, no network) and the test owns the response
     * shape. Each test that calls refund() declares its own shouldReceive('refundStorePayment')
     * with the return it needs; this helper only sets up shouldIgnoreMissing() for the rest.
     */
    private function fakeGateways(): MockInterface
    {
        return $this->mock(Gateways::class, function ($mock) {
            $mock->shouldIgnoreMissing();
        });
    }

    public function test_required_only_above_min_party(): void
    {
        $r = $this->storeFixture();
        $this->assertFalse(ReservationDepositService::required($r->id, 3)); // below min 4
        $this->assertTrue(ReservationDepositService::required($r->id, 4));
    }

    public function test_amount_per_guest_vs_fixed(): void
    {
        $r = $this->storeFixture(['reservation_deposit_per' => 'guest', 'reservation_deposit_amount' => 10]);
        $this->assertEqualsWithDelta(40.0, ReservationDepositService::amountFor($r->id, 4), 0.001);

        $r2 = $this->storeFixture(['reservation_deposit_per' => 'reservation', 'reservation_deposit_amount' => 25]);
        $this->assertEqualsWithDelta(25.0, ReservationDepositService::amountFor($r2->id, 6), 0.001);
    }

    public function test_initiate_then_confirm_marks_paid_and_confirmed(): void
    {
        $r = $this->storeFixture();
        $res = $this->reservation($r, 4);
        $txn = ReservationDepositService::initiate($res, 'stripe');
        $this->assertSame('pending', $res->fresh()->deposit_status);
        $this->assertEqualsWithDelta(40.0, (float) $res->fresh()->deposit_amount, 0.001);

        // Gateway confirms via the shared choke point.
        Fulfilment::fulfil($txn->fresh());
        $res->refresh();
        $this->assertSame('paid', $res->deposit_status);
        $this->assertSame('confirmed', $res->status);

        // Merchant holds the deposit.
        $ownerId = (int) $r->user_id;
        $merchant = LedgerService::accountFor('merchant', $ownerId, 'merchant', 'USD');
        $this->assertEqualsWithDelta(40.0, LedgerService::balanceFor($merchant), 0.001);
    }

    public function test_cancel_refunds_per_full_policy(): void
    {
        $mock = $this->fakeGateways();
        $r = $this->storeFixture(['reservation_deposit_refund' => 'full']);
        $res = $this->reservation($r, 4);
        ReservationDepositService::markPaid($res, ReservationDepositService::initiate($res, 'stripe'));

        $mock->shouldReceive('refundStorePayment')->once()->andReturn([
            'automatic' => true, 'gateway' => 'stripe', 'reference' => 're_1', 'message' => null,
        ]);

        $refund = ReservationDepositService::refund($res->fresh());
        $this->assertEqualsWithDelta(40.0, $refund, 0.001);
        $this->assertSame('refunded', $res->fresh()->deposit_status);
    }

    public function test_partial_refund_policy(): void
    {
        $mock = $this->fakeGateways();
        $r = $this->storeFixture(['reservation_deposit_refund' => 'partial', 'reservation_deposit_refund_pct' => 50]);
        $res = $this->reservation($r, 4);
        ReservationDepositService::markPaid($res, ReservationDepositService::initiate($res, 'stripe'));

        $mock->shouldReceive('refundStorePayment')->once()->andReturn([
            'automatic' => true, 'gateway' => 'stripe', 'reference' => 're_1', 'message' => null,
        ]);

        $refund = ReservationDepositService::refund($res->fresh());
        $this->assertEqualsWithDelta(20.0, $refund, 0.001); // 50% of 40
    }

    public function test_no_show_keeps_deposit(): void
    {
        $r = $this->storeFixture();
        $res = $this->reservation($r, 4);
        ReservationDepositService::markPaid($res, ReservationDepositService::initiate($res, 'stripe'));

        ReservationDepositService::keep($res->fresh());
        $this->assertSame('kept', $res->fresh()->deposit_status);
    }

    public function test_mark_paid_is_idempotent(): void
    {
        $r = $this->storeFixture();
        $res = $this->reservation($r, 4);
        $txn = ReservationDepositService::initiate($res, 'stripe');
        ReservationDepositService::markPaid($res->fresh(), $txn);
        ReservationDepositService::markPaid($res->fresh(), $txn); // replay

        $ownerId = (int) $r->user_id;
        $merchant = LedgerService::accountFor('merchant', $ownerId, 'merchant', 'USD');
        $this->assertEqualsWithDelta(40.0, LedgerService::balanceFor($merchant), 0.001); // not doubled
    }
}
