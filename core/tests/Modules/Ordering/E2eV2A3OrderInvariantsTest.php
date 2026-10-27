<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Tests\TestCase;

/**
 * Platform E2E v2 — A3 order-state invariants (server side).
 *
 * The customer-facing half of A3 (track-state fidelity, curbside/review
 * preconditions) lives in the puppeteer journey a-order-state.js. This test pins
 * the money-integrity invariants that no customer path can reach but that a
 * tampered/over-eager staff action must never breach:
 *   • a refund can never exceed the amount actually collected (refund > paid);
 *   • an UNPAID order cannot be refunded at all (void it instead).
 * Both are enforced in PosService::refund and are the discriminating core of
 * "refund>paid blocked; illegal transition rejected".
 */
class E2eV2A3OrderInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(100000, 999999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
    }

    /**
     * A paid order whose live total is exactly `$total`. `Order::total` (and thus
     * `amount_due`) always DERIVES from the lines (never reads the snapshot
     * column), so we anchor it on one menu line priced $total × qty 1, with the
     * unit_price snapshot pinned so no live-menu drift can move it.
     */
    private function paidOrder(Store $r, float $total): Order
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Cat', 'active' => '1']);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id,
            'name' => 'Line', 'price' => $total, 'active' => '1',
        ]);
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 1,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1,
            'variation' => 0, 'unit_price' => $total,
        ]);

        return $order->fresh();
    }

    public function test_refund_cannot_exceed_amount_collected(): void
    {
        // Assertions read the refund LEDGER (locale-independent), not message text
        // — the test env runs in Arabic, so the thrown wording is not English.
        $r = $this->storeFixture();
        $order = $this->paidOrder($r, 100.00);
        $refunded = fn () => (float) OrderAdjustment::where('order_id', $order->id)->where('type', 'refund')->sum(DB::raw(OrderAdjustment::minorMoneySql('amount')));

        // Over-refund (150 > 100 collected) is rejected and writes NO adjustment.
        $threw = false;
        try {
            PosService::refund($order, null, 150.00, 'cash', 'e2e-a3 over-refund');
        } catch (ValidationException $e) {
            $threw = true;
            $this->assertArrayHasKey('amount', $e->errors());
        }
        $this->assertTrue($threw, 'refund of 150 on a 100 order must throw');
        $this->assertEqualsWithDelta(0.0, $refunded(), 0.001, 'a rejected over-refund writes no refund row');

        // The full collected amount IS refundable exactly once — this proves the
        // 150 rejection was the cap, not a blanket denial.
        PosService::refund($order, null, 100.00, 'cash', 'e2e-a3 full');
        $this->assertEqualsWithDelta(100.00, $refunded(), 0.001, 'exactly the collected amount is refundable');

        // Any further refund past the collected total is rejected, ledger unchanged.
        $threw = false;
        try {
            PosService::refund($order->fresh(), null, 0.01, 'cash', 'e2e-a3 over-cap');
        } catch (ValidationException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'refund past the collected total must throw');
        $this->assertEqualsWithDelta(100.00, $refunded(), 0.001, 'over-cap refund writes no extra row');
    }

    public function test_unpaid_order_cannot_be_refunded(): void
    {
        $r = $this->storeFixture();
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 0,
        ]);

        $this->expectException(ValidationException::class);
        PosService::refund($order, null, 10.00, 'cash', 'e2e-a3 unpaid');
    }
}
