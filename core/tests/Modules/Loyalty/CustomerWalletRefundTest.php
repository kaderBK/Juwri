<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Coupons & Wallets P3-5: owner refunds an order to the customer's store credit.
 */
class CustomerWalletRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
    }

    /** A 20.00 order (one priced line): SEC-1119 — an order with no priced lines has nothing to refund. */
    private function pricedOrder(Store $r, ?Customer $customer): Order
    {
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'X', 'price' => 20, 'active' => '1']);
        $order = Order::create(['store_id' => $r->id, 'customer_id' => $customer?->id, 'type' => 'takeaway', 'status' => 'completed']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0]);

        return $order->fresh();
    }

    public function test_owner_refunds_order_to_customer_store_credit(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        $order = $this->pricedOrder($r, $customer);

        $this->actingAs($owner)
            ->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 18.50])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsWithDelta(18.5, CustomerWalletService::balanceFor($customer), 0.001);
    }

    public function test_double_refund_credits_only_once(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        $order = $this->pricedOrder($r, $customer);

        // A double click replays the same request (same idempotency key): answered OK, credited once.
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 20, 'idempotency_key' => 'k1'])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 20, 'idempotency_key' => 'k1'])->assertOk();
        // A fresh second refund of the same order finds nothing left to refund.
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 20])->assertStatus(422);

        $this->assertEqualsWithDelta(20.0, CustomerWalletService::balanceFor($customer), 0.001, 'refund applied once');
    }

    public function test_other_owner_cannot_refund(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $intruder = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        $order = $this->pricedOrder($r, $customer);

        $this->actingAs($intruder)
            ->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 10])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)

        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer), 0.001);
    }

    public function test_order_without_customer_is_rejected(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        $order = $this->pricedOrder($r, null);

        $this->actingAs($owner)
            ->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 10])
            ->assertStatus(422);
    }
}
