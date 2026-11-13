<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use App\Http\Middleware\Installed;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P1-23 part 6 — the two wallets never pay for each other. A store customer's STORE CREDIT (CustomerWalletService:
 * per customer, per store, the store's currency) only tenders that store's orders; the PLATFORM wallet (WalletService:
 * per account, the platform currency) only pays platform purchases. Top-ups land in the wallet they were bought for —
 * a store-credit top-up is a store payment (store keys), a platform top-up a platform purchase (platform keys).
 */
class WalletSeparationTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private Store $store;

    private User $person;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $currency = app(Ledger::class)->defaultCurrency();
        [$this->store] = $this->makeOrderTransaction('stripe', 1.0, ['secret_key' => 'sk_test_store'], $currency);
        StoreOption::updateStoreOption($this->store->id, 'currency_code', $currency);
        // The same person: a platform account (e.g. another store's owner) AND a customer of this store.
        $this->person = User::factory()->create(['email' => 'same@example.com', 'status' => 1, 'email_verified_at' => now()]);
        $this->customer = Customer::create(['store_id' => $this->store->id, 'name' => 'Same Person', 'email' => 'same@example.com', 'phone' => '5000001']);
    }

    public function test_a_store_credit_top_up_lands_in_store_credit_only(): void
    {
        ['transaction' => $tx] = CustomerWalletService::initiateTopup((int) $this->store->id, 10.0, 'stripe', (int) $this->customer->id);
        $this->assertTrue(Settlement::usesStoreGateway($tx), 'a store payment: charged on the store account');

        Settlement::settle($tx->fresh(), 'stripe', 'pi_1', claim: true);

        $this->assertEqualsWithDelta(10.0, CustomerWalletService::balanceFor($this->customer), 0.0001);
        $this->assertEqualsWithDelta(0.0, WalletService::balanceFor($this->person), 0.0001);
    }

    public function test_a_platform_wallet_top_up_lands_in_the_platform_wallet_only(): void
    {
        $this->requiresEditionPackage('a platform wallet top-up is credited by Billing');
        [, $tx] = $this->makePlanTransaction(49.0, app(Ledger::class)->defaultCurrency(), 'wallet_topup');
        Transaction::whereKey($tx->id)->update(['user_id' => $this->person->id]);
        $this->assertFalse(Settlement::usesStoreGateway($tx->fresh()), 'a platform purchase: charged on the platform account');

        Settlement::settle($tx->fresh(), 'stripe', 'pi_2', claim: true);

        $this->assertEqualsWithDelta(49.0, WalletService::balanceFor($this->person), 0.0001);
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($this->customer), 0.0001);
    }

    public function test_store_credit_never_pays_a_platform_purchase(): void
    {
        CustomerWalletService::credit($this->customer, 50.0, 'goodwill', 'test:goodwill');

        try {
            WalletService::debit($this->person, 10.0, 'Theme purchase', 'theme:1');
            $this->fail('the platform wallet is empty: the purchase must be refused');
        } catch (\RuntimeException) {
            // refused — store credit is not platform money
        }

        $this->assertEqualsWithDelta(50.0, CustomerWalletService::balanceFor($this->customer), 0.0001);
        $this->assertEqualsWithDelta(0.0, WalletService::balanceFor($this->person), 0.0001);
    }

    public function test_the_platform_wallet_never_pays_a_store_order(): void
    {
        WalletService::credit($this->person, 50.0, 'top-up', 'wtx:1');
        CustomerWalletService::credit($this->customer, 5.0, 'goodwill', 'test:goodwill');
        $order = Order::create(['store_id' => $this->store->id, 'track_token' => 'trk-sep-1', 'is_paid' => 0, 'status' => 'pending', 'type' => 'takeaway', 'created_at' => now()]);
        $order->forceFill(['customer_id' => $this->customer->id, 'service_charge_amount' => 30.0])->save(); // a 30.00 order

        $applied = CustomerWalletService::applyToOrder($this->customer, $order->fresh(), 30.0);

        $this->assertEqualsWithDelta(5.0, $applied, 0.0001, 'only the store credit tenders the order');
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($this->customer), 0.0001);
        $this->assertEqualsWithDelta(50.0, WalletService::balanceFor($this->person), 0.0001, 'the platform wallet is untouched');
    }
}
