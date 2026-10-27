<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Coupons & Wallets P3-1: customer store-credit account exists on the unified
 * ledger and is entirely separate from loyalty POINTS (D4).
 */
class CustomerWalletCreditTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '123',
        ]);
    }

    private function customer(Store $r): Customer
    {
        return Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
    }

    private function credit(Customer $c, float $amount): void
    {
        $acct = CustomerWalletService::accountFor($c);
        $clr = LedgerService::accountFor('platform', 0, 'platform', $acct->currency);
        LedgerService::post([
            ['account' => $clr, 'amount' => -$amount, 'allow_negative' => true, 'ref' => "sc:{$c->id}:clr"],
            ['account' => $acct, 'amount' => $amount, 'ref' => "sc:{$c->id}:usr"],
        ]);
    }

    public function test_store_credit_starts_at_zero(): void
    {
        $c = $this->customer($this->storeFixture());
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($c), 0.001);
    }

    public function test_store_credit_is_separate_from_loyalty_points(): void
    {
        $r = $this->storeFixture();
        $c = $this->customer($r);

        $this->credit($c, 40.00);
        $loyalty = LoyaltyAccount::forCustomer($c->id, $r->id);
        $loyalty->earn(999, 5, 'points'); // 5 stamps

        $this->assertEqualsWithDelta(40.0, CustomerWalletService::balanceFor($c), 0.001, 'cash credit unaffected by points');
        $this->assertSame(5, (int) $loyalty->fresh()->balance, 'points unaffected by cash credit');
    }

    public function test_each_customer_has_its_own_store_credit(): void
    {
        $r = $this->storeFixture();
        $a = $this->customer($r);
        $b = $this->customer($r);

        $this->credit($a, 25.00);

        $this->assertEqualsWithDelta(25.0, CustomerWalletService::balanceFor($a), 0.001);
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($b), 0.001);
    }

    public function test_credit_primitive_adds_balance_and_is_idempotent(): void
    {
        $c = $this->customer($this->storeFixture());

        CustomerWalletService::credit($c, 15.00, 'goodwill', 'gw:1');
        CustomerWalletService::credit($c, 15.00, 'goodwill dup', 'gw:1'); // replay

        $this->assertEqualsWithDelta(15.0, CustomerWalletService::balanceFor($c), 0.001);
    }

    public function test_refund_to_credit_is_idempotent_per_order(): void
    {
        // P3-2: a double-fired refund for the same order credits only once.
        $c = $this->customer($this->storeFixture());

        CustomerWalletService::refundToCredit($c, 22.50, 7788);
        CustomerWalletService::refundToCredit($c, 22.50, 7788);

        $this->assertEqualsWithDelta(22.5, CustomerWalletService::balanceFor($c), 0.001);
    }
}
