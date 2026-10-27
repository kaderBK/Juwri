<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Customer store credit is restaurant-scoped. CustomerWalletService::applyToOrder must
 * refuse to tender a customer's balance against an order at a DIFFERENT restaurant —
 * otherwise a staff/owner of store A could pass store B's customer_id + use_wallet at
 * order intake and drain that foreign customer's balance. Both stores here share the
 * default currency, so ONLY the tenant fence (not the currency guard) can stop it.
 */
class WalletTenantFenceTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'x',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A delivery order worth 100 (delivery charge only, no line items needed). */
    private function orderWorth100(Store $store): Order
    {
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_charge', 100);

        return Order::create([
            'store_id' => $store->id, 'type' => 'delivery', 'status' => 'pending',
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);
    }

    private function customerWithCredit(Store $store, float $amount): Customer
    {
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'C', 'phone' => Str::random(10)]);
        CustomerWalletService::credit($customer, $amount, 'seed', 'seed:'.Str::random(6));

        return $customer;
    }

    public function test_foreign_customer_credit_cannot_be_applied(): void
    {
        $storeA = $this->store();
        $storeB = $this->store();

        $orderA = $this->orderWorth100($storeA);
        $customerB = $this->customerWithCredit($storeB, 200);

        $applied = CustomerWalletService::applyToOrder($customerB, $orderA, 50);

        $this->assertSame(0.0, $applied, 'a store-B customer must not tender against a store-A order');
        $this->assertSame(200.0, CustomerWalletService::balanceFor($customerB), 'foreign balance must be untouched');
        $this->assertSame(0.0, (float) $orderA->fresh()->wallet_applied);
    }

    public function test_same_store_customer_credit_applies(): void
    {
        $storeA = $this->store();

        $orderA = $this->orderWorth100($storeA);
        $customerA = $this->customerWithCredit($storeA, 200);

        $applied = CustomerWalletService::applyToOrder($customerA, $orderA, 50);

        $this->assertSame(50.0, $applied, 'a same-store customer tenders normally');
        $this->assertSame(150.0, CustomerWalletService::balanceFor($customerA));
    }
}
