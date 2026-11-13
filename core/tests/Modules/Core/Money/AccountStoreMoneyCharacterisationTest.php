<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Http\Middleware\Installed;
use App\Models\Reservation;
use App\Models\StoreOption;
use App\Services\ReservationDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-05 slice 6c (characterisation): the public money behaviour of the last account/store columns — the reservation
 * deposit (store-precision string, deposit ledger, refund policy), the marketplace minimum order on the store
 * (number / null / JSON) and the legacy user balance (number, JSON key). Written against the decimal/float columns
 * BEFORE the move to `*_minor` and kept green after it: only the storage changes.
 */
class AccountStoreMoneyCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.currency_code' => 'USD']);
        $this->withoutMiddleware([Installed::class]);
    }

    private function store(array $opts = [], array $attrs = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create($attrs + [
            'user_id' => $owner->id, 'slug' => 'm-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        foreach ($opts + [
            'reservation_deposit_enabled' => '1', 'reservation_deposit_amount' => 10,
            'reservation_deposit_per' => 'guest', 'reservation_deposit_min_party' => 1,
            'reservation_deposit_refund' => 'partial', 'reservation_deposit_refund_pct' => 50, 'currency_code' => 'USD',
        ] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    private function reservation(Store $store, int $party, array $attrs = []): Reservation
    {
        // store_id first: the old StoreMoney cast resolved the store's decimals when the amount was SET.
        return Reservation::create(array_merge([
            'store_id' => $store->id, 'name' => 'Guest', 'party_size' => $party,
            'reserved_at' => now()->addDay(), 'status' => 'pending',
        ], $attrs));
    }

    public function test_reservation_deposit_shapes_ledger_and_refund(): void
    {
        $store = $this->store(['reservation_deposit_amount' => 12.5]);
        $res = $this->reservation($store, 3);
        $this->assertSame('0.00', $res->fresh()->deposit_amount, 'column default, store precision');

        $txn = ReservationDepositService::initiate($res, 'stripe');
        $this->assertSame(37.5, (float) $txn->amount);
        $fresh = $res->fresh();
        $this->assertSame('37.50', $fresh->deposit_amount);
        $this->assertSame('USD', $fresh->currency);
        $this->assertSame('37.50', $fresh->toArray()['deposit_amount']);

        ReservationDepositService::markPaid($fresh, $txn);
        $merchant = LedgerService::accountFor('merchant', (int) $store->user_id, 'merchant', 'USD');
        $this->assertSame(37.5, LedgerService::balanceFor($merchant));

        // P6-336: refund() goes through the store gateway; the provider confirms (fake, no network)
        $this->mock(Gateways::class, fn ($m) => $m->shouldReceive('refundStorePayment')->once()
            ->andReturn(['automatic' => true, 'gateway' => 'stripe', 'reference' => 're_t', 'message' => null]));
        $this->assertSame(18.75, ReservationDepositService::refund($res->fresh()));
        $this->assertSame(18.75, LedgerService::balanceFor($merchant));

        // A 3-decimal store shows (and keeps) its fils.
        $omr = $this->store(['currency_code' => 'OMR']);
        $r2 = $this->reservation($omr, 2, ['deposit_amount' => 0.275, 'currency' => 'OMR']);
        $this->assertSame('0.275', $r2->fresh()->deposit_amount);
        $this->assertSame(0.275, (float) $r2->fresh()->deposit_amount);
    }

    public function test_store_minimum_order_shape(): void
    {
        $store = $this->store([], ['mk_min_order' => 25.5]);
        $this->assertSame(25.5, $store->fresh()->mk_min_order);
        $this->assertSame(25.5, $store->fresh()->toArray()['mk_min_order']);

        $store->mk_min_order = null;
        $store->save();
        $this->assertNull($store->fresh()->mk_min_order);

        $store->update(['mk_min_order' => '30']);
        $this->assertSame(30.0, $store->fresh()->mk_min_order);
        $this->assertNull($this->store()->fresh()->mk_min_order, 'nullable, no default');
    }

    public function test_legacy_user_balance_shape(): void
    {
        $u = User::factory()->create()->fresh();
        $this->assertEquals(0, $u->balance);
        $this->assertArrayHasKey('balance', $u->toArray());

        $u->balance = 12.5;
        $u->save();
        $this->assertEquals(12.5, $u->fresh()->balance);
    }
}
