<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Refunds\GatewayRefundService;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Refund an order back to its ORIGINAL payment (card/gateway) — as opposed to
 * store credit. With no live driver configured, the refund is recorded as a
 * tracked manual refund (automatic=false) and the order is marked settled.
 */
class OrderRefundToOriginalTest extends TestCase
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

    private function paidOrder(Store $r, ?Customer $c = null): Order
    {
        return Order::create([
            'store_id' => $r->id, 'customer_id' => $c?->id, 'type' => 'takeaway',
            'status' => 'completed', 'is_paid' => 1, 'grand_total' => 30, 'subtotal' => 30,
            'refund_request_status' => 'requested',
        ]);
    }

    public function test_owner_refunds_to_original_marks_settled_and_method(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        $order = $this->paidOrder($r, $customer);

        $this->actingAs($owner)
            ->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 30])
            ->assertOk()
            // No live driver in tests → tracked manual refund.
            ->assertJson(['success' => true, 'automatic' => false]);

        $order->refresh();
        $this->assertSame('approved', $order->refund_request_status);
        $this->assertSame('original_payment', $order->refund_method);
    }

    public function test_unpaid_order_cannot_refund_to_original(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 0]);

        $this->actingAs($owner)
            ->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 10])
            ->assertStatus(422);
    }

    public function test_double_refund_to_original_is_refused(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        $order = $this->paidOrder($r);

        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 30])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 30])->assertStatus(422);
    }

    public function test_other_owner_cannot_refund_to_original(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $intruder = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        $order = $this->paidOrder($r);

        $this->actingAs($intruder)
            ->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 10])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)

        // No money moved: the order is still an open, unsettled refund request.
        $order->refresh();
        $this->assertSame('requested', $order->refund_request_status);
        $this->assertNull($order->refund_method);
        $this->assertSame(1, (int) $order->is_paid);
    }

    public function test_resolve_gateway_prefers_stamp_then_transaction(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);

        $stamped = $this->paidOrder($r);
        $stamped->forceFill(['payment_gateway' => 'stripe'])->save();
        $this->assertSame('stripe', GatewayRefundService::resolveGateway($stamped));

        $viaTx = $this->paidOrder($r);
        Transaction::create([
            'user_id' => $owner->id, 'product_id' => $viaTx->id, 'transaction_method' => 'tap',
            'price' => 30, 'status' => 1,
        ]);
        $this->assertSame('tap', GatewayRefundService::resolveGateway($viaTx));

        $cash = $this->paidOrder($r);
        $this->assertNull(GatewayRefundService::resolveGateway($cash));
    }
}
