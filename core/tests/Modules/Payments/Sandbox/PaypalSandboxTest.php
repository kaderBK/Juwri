<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Sandbox;

use App\Models\Order;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaypalController;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * Phase 4 item 18 — PayPal sandbox (api-m.sandbox.paypal.com; developer.paypal.com/api/rest/webhooks): order →
 * approve link → return capture or CHECKOUT.ORDER.APPROVED webhook → paid; refund; declined instrument; duplicate,
 * unsigned and wrongly signed notifications; the sandbox flag. PayPal signs with a certificate it verifies itself
 * (POST /v1/notifications/verify-webhook-signature), so the vector is the documented transmission headers + that answer.
 */
class PaypalSandboxTest extends SandboxTestCase
{
    protected function gateway(): string
    {
        return 'paypal';
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'settings.paypal_api_client_id' => 'sandbox-platform-client', 'settings.paypal_api_secret' => 'sandbox-platform-secret',
            'settings.paypal_sandbox_mode' => 'Yes', 'settings.paypal_payment_mode' => 'one_time', 'settings.paypal_webhook_id' => 'WH-TEST-VECTOR',
        ]);
    }

    /** @return array{0: Store, 1: Order, 2: Transaction} */
    private function paypalOrder(): array
    {
        return $this->order(['api_client_id' => 'sandbox-store-client', 'api_secret' => 'sandbox-store-secret']);
    }

    /** @return array<string, mixed> */
    private function captured(string $orderId, string $value = '100.00', string $status = 'COMPLETED'): array
    {
        return ['id' => $orderId, 'status' => $status, 'purchase_units' => [['payments' => ['captures' => [
            ['id' => 'CAP-'.$orderId, 'status' => $status, 'amount' => ['currency_code' => 'SAR', 'value' => $value]],
        ]]]]];
    }

    /** @return array<string, string> the documented transmission headers */
    private function signedHeaders(): array
    {
        return [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-test-vector',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'TX-test-vector',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => base64_encode('test-vector-signature'),
            'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601String(),
        ];
    }

    private function approved(string $eventId, string $orderId): string
    {
        return json_encode(['id' => $eventId, 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => $orderId, 'status' => 'APPROVED']]);
    }

    private function base(): array
    {
        return ['POST /v1/oauth2/token' => ['access_token' => 'A21-sandbox-token', 'expires_in' => 300]];
    }

    public function test_create_approve_link_then_the_return_captures_and_settles_once(): void
    {
        [, $order, $tx] = $this->paypalOrder();
        $this->fake($this->base() + [
            'POST /v2/checkout/orders/ORD-1/capture' => $this->captured('ORD-1'),
            'POST /v2/checkout/orders' => ['id' => 'ORD-1', 'status' => 'CREATED', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORD-1']]],
        ]);

        $start = PaypalController::adapter()->initiate($tx->fresh());
        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=ORD-1', $start->headers->get('Location'));
        Http::assertSent(fn (ClientRequest $r) => str_starts_with($r->url(), 'https://api-m.sandbox.paypal.com/'));

        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORD-1')->assertOk();
        $this->assertSettledOnce($order, $tx);
        $this->assertCreditedOnce($tx);
    }

    public function test_a_buyer_who_never_returns_is_settled_by_the_signed_webhook_once(): void
    {
        [, $order, $tx] = $this->paypalOrder();
        $tx->update(['payment_id' => 'ORD-2']);
        $this->fake($this->base() + [
            'POST /v1/notifications/verify-webhook-signature' => ['verification_status' => 'SUCCESS'],
            'POST /v2/checkout/orders/ORD-2/capture' => $this->captured('ORD-2'),
        ]);

        $body = $this->approved('WH-EVT-1', 'ORD-2');
        $this->webhook($body, $this->signedHeaders())->assertOk();
        $this->assertSettledOnce($order, $tx);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/verify-webhook-signature') && $r['webhook_id'] === 'WH-TEST-VECTOR' && $r['transmission_id'] === 'TX-test-vector');

        // PayPal re-delivers the same event: a no-op
        $this->webhook($body, $this->signedHeaders())->assertOk()->assertJson(['message' => 'duplicate']);
        $this->assertCreditedOnce($tx);

        // the buyer comes back after all: the capture is refused (already captured) — they see the paid order
        $this->fake($this->base() + [
            'POST /v2/checkout/orders/ORD-2/capture' => ['__status' => 422, 'body' => ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]]],
            'GET /v2/checkout/orders/ORD-2' => $this->captured('ORD-2'),
        ]);
        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORD-2')->assertRedirect();
        $this->assertCreditedOnce($tx);
    }

    public function test_unsigned_and_wrongly_signed_notifications_are_rejected(): void
    {
        [, $order, $tx] = $this->paypalOrder();
        $tx->update(['payment_id' => 'ORD-3']);
        $body = $this->approved('WH-EVT-3', 'ORD-3');
        $this->fake($this->base() + ['POST /v1/notifications/verify-webhook-signature' => ['verification_status' => 'FAILURE']]);

        $this->webhook($body)->assertStatus(400); // no transmission headers
        $this->webhook($body, $this->signedHeaders())->assertStatus(400); // PayPal: does not verify
        config(['settings.paypal_webhook_id' => '']);
        $this->webhook($body, $this->signedHeaders())->assertStatus(400); // no webhook id: unsigned = refused
        $this->webhook('not json', $this->signedHeaders())->assertStatus(400);

        $this->assertNotSettled($order, $tx);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/capture'));
    }

    public function test_a_declined_instrument_does_not_settle(): void
    {
        [, $order, $tx] = $this->paypalOrder();
        $tx->update(['payment_id' => 'ORD-4']);
        $this->fake($this->base() + ['POST /v2/checkout/orders/ORD-4/capture' => ['__status' => 422, 'body' => [
            'name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']],
        ]]]);

        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORD-4')->assertRedirect();
        $this->assertNotSettled($order, $tx);
    }

    public function test_refund_goes_to_the_capture(): void
    {
        [, $tx] = $this->makePlanTransaction(100.0, 'SAR', 'wallet_topup');
        $tx->update(['payment_id' => 'ORD-5', 'status' => Transaction::STATUS_SUCCESS, 'transaction_gatway' => 'paypal']);
        $this->fake($this->base() + [
            'GET /v2/checkout/orders/ORD-5' => $this->captured('ORD-5'),
            'POST /v2/payments/captures/CAP-ORD-5/refund' => ['id' => 'REF-5', 'status' => 'COMPLETED'],
        ]);

        $this->assertSame(['ok' => true, 'reference' => 'REF-5', 'message' => null], PaypalController::adapter()->refund($tx->fresh(), 40.0));
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/captures/CAP-ORD-5/refund') && $r['amount'] === ['value' => '40.00', 'currency_code' => 'SAR']);
    }

    public function test_with_sandboxes_off_sandbox_mode_pays_nothing_and_cannot_verify(): void
    {
        config(['payments.sandbox.enabled' => false]);
        [, $order, $tx] = $this->paypalOrder();
        $this->fake($this->base() + ['POST /v1/notifications/verify-webhook-signature' => ['verification_status' => 'SUCCESS']]);

        PaypalController::adapter()->initiate($tx->fresh());
        $tx->update(['payment_id' => 'ORD-6']);
        $this->webhook($this->approved('WH-EVT-6', 'ORD-6'), $this->signedHeaders())->assertStatus(400);

        Http::assertNothingSent();
        $this->assertNotSettled($order, $tx);
    }
}
