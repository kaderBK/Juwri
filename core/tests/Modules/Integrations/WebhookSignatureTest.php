<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * S10 — gateway webhook signature sweep + throttle. Razorpay now verifies its
 * HMAC with constant-time hash_equals (a byte-shifted signature no longer
 * settles), and the generic ipn/webhook routes are rate-limited.
 */
class WebhookSignatureTest extends TestCase
{
    use GatewayTestHelpers, RefreshDatabase;

    private function seedRazorpay(): array
    {
        PaymentGateway::create([
            'payment_folder' => 'razorpay', 'payment_title' => 'Razorpay', 'payment_install' => '1',
        ]);

        [$store, $order, $tx] = $this->makeOrderTransaction('razorpay', 100.0, ['secret_key' => 'sek_test']);

        $orderId = 'order_ABC123';
        $tx->update(['payment_id' => $orderId, 'status' => null]);
        StoreOption::updateStoreOption($store->id, 'restaurant_razorpay_secret_key', 'sek_test');

        return [$store, $order, $tx, $orderId];
    }

    private function validSignature(string $orderId, string $paymentId, string $secret): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, $secret);
    }

    public function test_razorpay_rejects_tampered_signature(): void
    {
        [, $order, $tx, $orderId] = $this->seedRazorpay();
        $paymentId = 'pay_XYZ';
        $good = $this->validSignature($orderId, $paymentId, 'sek_test');
        $tampered = substr($good, 0, -1).($good[-1] === '0' ? '1' : '0');

        $this->withoutMiddleware([Installed::class])
            ->post(route('ipn', 'razorpay'), [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $tampered,
            ]);

        $this->assertNull($tx->fresh()->status, 'tampered signature must not settle the transaction');
        $this->assertSame(0, (int) $order->fresh()->is_paid, 'order must stay unpaid');
    }

    public function test_razorpay_accepts_valid_signature(): void
    {
        [, $order, , $orderId] = $this->seedRazorpay();
        $paymentId = 'pay_XYZ';
        $good = $this->validSignature($orderId, $paymentId, 'sek_test');

        $this->withoutMiddleware([Installed::class])
            ->post(route('ipn', 'razorpay'), [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $good,
            ]);

        $this->assertSame(1, (int) $order->fresh()->is_paid, 'valid signature must settle the order');
    }

    public function test_generic_ipn_route_is_throttled(): void
    {
        $last = null;
        for ($i = 0; $i < 121; $i++) {
            $last = $this->withoutMiddleware([Installed::class])
                ->post(route('ipn', 'no_such_gateway'), []);
        }

        $this->assertSame(429, $last->getStatusCode(), 'ipn/{gateway} must throttle at 120/min');
    }
}
