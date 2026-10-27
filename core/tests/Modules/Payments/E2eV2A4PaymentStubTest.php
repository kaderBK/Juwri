<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Order;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use App\Models\OrderItem;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Platform E2E v2 — A4 partial-refund ledger on a settled order.
 *
 * The a-payment-stub.js journey proves the stub gateway settles (success) and
 * declines (fail) at checkout. This pins the third A4 requirement: a PARTIAL
 * refund on a settled (paid) order records exactly the partial amount and leaves
 * the correct remaining refundable — the money never over- or under-refunds.
 */
class E2eV2A4PaymentStubTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrder(float $total): Order
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(100000, 999999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'active' => '1']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'L', 'price' => $total, 'active' => '1']);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 1]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0, 'unit_price' => $total]);

        return $order->fresh();
    }

    public function test_partial_refund_records_partial_and_leaves_remainder(): void
    {
        $order = $this->paidOrder(100.00);
        $refunded = fn () => (float) OrderAdjustment::where('order_id', $order->id)->where('type', 'refund')->sum(\Illuminate\Support\Facades\DB::raw(OrderAdjustment::minorMoneySql('amount')));

        // Partial refund of 30 of 100 — the ledger records exactly 30.
        PosService::refund($order, null, 30.00, 'cash', 'e2e-a4 partial');
        $this->assertEqualsWithDelta(30.00, $refunded(), 0.001, 'partial refund records the partial amount');

        // A second partial (40) is allowed — cumulative 70, still ≤ collected.
        PosService::refund($order->fresh(), null, 40.00, 'cash', 'e2e-a4 partial-2');
        $this->assertEqualsWithDelta(70.00, $refunded(), 0.001, 'partial refunds accumulate');

        // The remaining refundable is 30; a 31 refund is rejected (over the cap).
        $threw = false;
        try {
            PosService::refund($order->fresh(), null, 31.00, 'cash', 'e2e-a4 over');
        } catch (ValidationException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'refund beyond the remaining refundable must throw');
        $this->assertEqualsWithDelta(70.00, $refunded(), 0.001, 'the rejected refund adds nothing');

        // Exactly the remainder (30) settles the order fully.
        PosService::refund($order->fresh(), null, 30.00, 'cash', 'e2e-a4 remainder');
        $this->assertEqualsWithDelta(100.00, $refunded(), 0.001, 'the remainder is refundable and totals the collected amount');
    }
}
