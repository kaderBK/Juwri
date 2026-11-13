<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * P6-203: TwoCheckoutAdapter and QrpayAdapter both report `supportsRefund()=false`,
 * so the in-app refund buttons record a TRACKED MANUAL refund. The owner-facing
 * order row must remind the owner that the money has to move in the provider's
 * dashboard first — without that prompt they'd click "Refund" expecting the
 * gateway to do it and wonder why nothing happened.
 *
 * Rendered as the owner Orders screen row partial (`owner.orders.order-row`).
 * The same gateway list lives in `core/resources/views/owner/orders/order-row.blade.php`;
 * if either adapter grows a live refund driver, drop the entry from BOTH places.
 */
class ManualRefundNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        app()->setLocale('en');
    }

    private function owner(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    private function storeFixture(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
    }

    private function paidOrder(Store $r, string $gateway): Order
    {
        return Order::create([
            'store_id' => $r->id, 'type' => 'takeaway', 'status' => 'completed',
            'is_paid' => 1, 'grand_total' => 30, 'subtotal' => 30,
            'payment_gateway' => $gateway,
            'track_token' => str()->random(12),
            'created_at' => now(),
        ]);
    }

    private function renderRow(Order $order): string
    {
        $opts = (object) [];

        return view('owner.orders.order-row', [
            'order' => $order->fresh('items'),
            'postOptions' => $opts,
        ])->render();
    }

    public function test_two_checkout_paid_order_carries_the_manual_refund_note(): void
    {
        $order = $this->paidOrder($this->storeFixture($this->owner()), 'two_checkout');
        $html = $this->renderRow($order);

        $this->assertStringContainsString('data-manual-refund-note', $html);
        $this->assertStringContainsString('data-gateway="two_checkout"', $html);
        $this->assertStringContainsString(
            ___('This gateway has no refund API. Refund the customer in the provider dashboard, then mark the order refunded here.'),
            $html
        );
    }

    public function test_qrpay_paid_order_carries_the_manual_refund_note(): void
    {
        $order = $this->paidOrder($this->storeFixture($this->owner()), 'qrpay');
        $html = $this->renderRow($order);

        $this->assertStringContainsString('data-manual-refund-note', $html);
        $this->assertStringContainsString('data-gateway="qrpay"', $html);
        $this->assertStringContainsString(
            ___('This gateway has no refund API. Refund the customer in the provider dashboard, then mark the order refunded here.'),
            $html
        );
    }

    public function test_stripe_paid_order_does_not_carry_the_manual_refund_note(): void
    {
        // Stripe IS refundable; the warning would be wrong and would actively
        // undermine the owner's trust in the in-app refund button.
        $order = $this->paidOrder($this->storeFixture($this->owner()), 'stripe');
        $html = $this->renderRow($order);

        $this->assertStringNotContainsString('data-manual-refund-note', $html);
        $this->assertStringNotContainsString(
            ___('This gateway has no refund API. Refund the customer in the provider dashboard, then mark the order refunded here.'),
            $html
        );
    }

    public function test_unpaid_two_checkout_order_does_not_carry_the_note(): void
    {
        // The note is about how to REFUND. An unpaid order has nothing to
        // refund — the message would be confusing on a not-yet-paid row.
        $order = Order::create([
            'store_id' => ($r = $this->storeFixture($this->owner()))->id,
            'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 0,
            'grand_total' => 30, 'subtotal' => 30,
            'payment_gateway' => 'two_checkout',
            'track_token' => str()->random(12),
            'created_at' => now(),
        ]);
        $html = $this->renderRow($order);

        $this->assertStringNotContainsString('data-manual-refund-note', $html);
    }
}
