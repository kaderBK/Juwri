<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * T4 — payment webhooks exercised over the real HTTP routes (webhook/{gateway},
 * ipn/{gateway}) and PaymentController's payment_folder→class resolution, not by
 * calling processWebhook() directly. Covers Stripe signature verify + dispute,
 * unknown-gateway 404, and Razorpay replay idempotency.
 */
class PaymentWebhookHttpTest extends TestCase
{
    use GatewayTestHelpers, RefreshDatabase;

    private function withoutInstalled(): self
    {
        $this->withoutMiddleware([Installed::class]);

        return $this;
    }

    private function stripeSignature(string $payload, string $secret, ?int $t = null): string
    {
        $t = $t ?? time();
        $sig = hash_hmac('sha256', $t.'.'.$payload, $secret);

        return "t={$t},v1={$sig}";
    }

    private function seedGateway(string $folder): void
    {
        PaymentGateway::create([
            'payment_folder' => $folder, 'payment_title' => ucfirst($folder), 'payment_install' => '1',
        ]);
    }

    public function test_unknown_gateway_folder_404s(): void
    {
        $this->withoutInstalled()
            ->postJson(route('webhook', 'no_such_gateway'), [])
            ->assertNotFound();
    }

    public function test_stripe_rejects_absent_and_tampered_signature(): void
    {
        $this->seedGateway('stripe');
        config(['settings.stripe_webhook_secret' => 'whsec_test', 'settings.stripe_secret_key' => 'sk_test']);

        $payload = json_encode(['type' => 'charge.dispute.created', 'data' => ['object' => ['id' => 'dp_1']]]);

        // Absent signature → 400.
        $this->withoutInstalled()
            ->call('POST', route('webhook', 'stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)
            ->assertStatus(400);

        // Tampered signature → 400.
        $bad = $this->stripeSignature($payload, 'whsec_test');
        $bad = substr($bad, 0, -1).($bad[-1] === '0' ? '1' : '0');
        $this->withoutInstalled()
            ->call('POST', route('webhook', 'stripe'), [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $bad,
            ], $payload)
            ->assertStatus(400);
    }

    public function test_stripe_valid_signature_reaches_dispute_branch(): void
    {
        $this->seedGateway('stripe');
        config(['settings.stripe_webhook_secret' => 'whsec_test', 'settings.stripe_secret_key' => 'sk_test']);

        $payload = json_encode(['type' => 'charge.dispute.created', 'data' => ['object' => ['id' => 'dp_1']]]);
        $good = $this->stripeSignature($payload, 'whsec_test');

        $this->withoutInstalled()
            ->call('POST', route('webhook', 'stripe'), [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $good,
            ], $payload)
            ->assertOk()
            ->assertJsonFragment(['message' => 'dispute recorded']);
    }

    public function test_razorpay_ipn_settles_once_and_replay_is_idempotent(): void
    {
        $this->seedGateway('razorpay');
        [$store, $order, $tx] = $this->makeOrderTransaction('razorpay', 100.0, ['secret_key' => 'sek']);
        $orderId = 'order_R1';
        $paymentId = 'pay_R1';
        $tx->update(['payment_id' => $orderId, 'status' => null]);
        StoreOption::updateStoreOption($store->id, 'restaurant_razorpay_secret_key', 'sek');

        $sig = hash_hmac('sha256', $orderId.'|'.$paymentId, 'sek');
        $body = ['razorpay_order_id' => $orderId, 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => $sig];

        $this->withoutInstalled()->post(route('ipn', 'razorpay'), $body);
        $this->assertSame(1, (int) $order->fresh()->is_paid, 'first valid callback settles the order');

        // Replay the same callback: the transaction was consumed, so no re-settle
        // and no error.
        $this->withoutInstalled()->post(route('ipn', 'razorpay'), $body);
        $this->assertSame(1, (int) $order->fresh()->is_paid, 'order stays paid, not double-processed');
    }
}
