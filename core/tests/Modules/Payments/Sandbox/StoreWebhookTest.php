<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Sandbox;

use App\Models\StoreOption;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * A store paying through its OWN Stripe / PayPal account: that account notifies `webhook/{gateway}/store/{store}` and
 * the payment settles even when the buyer closes the tab (before: only the buyer's return settled it). Verified with
 * THAT store's webhook secret / id only — another store's secret and the platform's are refused — and settled once;
 * only the store's own pending payments.
 */
class StoreWebhookTest extends SandboxTestCase
{
    private string $gw = 'stripe';

    protected function gateway(): string
    {
        return $this->gw;
    }

    private function storeWebhook(int $storeId, string $body, array $server = []): TestResponse
    {
        return $this->call('POST', route('webhook.store', ['gateway' => $this->gw, 'store' => $storeId]), [], [], [],
            array_merge(['CONTENT_TYPE' => 'application/json'], $server), $body);
    }

    private function stripeSigned(string $body, string $secret): array
    {
        $t = time();

        return ['HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, $secret)];
    }

    private function sessionEvent(string $eventId, string $sessionId): string
    {
        return json_encode(['id' => $eventId, 'type' => 'checkout.session.completed', 'livemode' => false, 'data' => ['object' => [
            'id' => $sessionId, 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'paid', 'status' => 'complete',
            'amount_total' => 10000, 'currency' => 'sar', 'payment_intent' => 'pi_'.$sessionId,
        ]]]);
    }

    public function test_a_stores_own_stripe_webhook_settles_once_and_only_with_its_own_secret(): void
    {
        config(['settings.stripe_webhook_secret' => 'whsec_platform_vector']);
        [$storeA, $orderA, $txA] = $this->order(['secret_key' => 'sk_test_a', 'webhook_secret' => 'whsec_store_a']);
        [$storeB, $orderB, $txB] = $this->order(['secret_key' => 'sk_test_b', 'webhook_secret' => 'whsec_store_b']);
        $txA->update(['payment_id' => 'cs_A']);
        $txB->update(['payment_id' => 'cs_B']);
        $body = $this->sessionEvent('evt_A', 'cs_A');

        // another store's secret, the platform's secret, no signature: refused
        $this->storeWebhook($storeA->id, $body, $this->stripeSigned($body, 'whsec_store_b'))->assertStatus(400);
        $this->storeWebhook($storeA->id, $body, $this->stripeSigned($body, 'whsec_platform_vector'))->assertStatus(400);
        $this->storeWebhook($storeA->id, $body)->assertStatus(400);
        $this->assertNotSettled($orderA, $txA);

        // store B's account cannot settle store A's payment through B's own route either
        $this->storeWebhook($storeB->id, $body, $this->stripeSigned($body, 'whsec_store_b'))->assertOk()->assertJson(['message' => 'nothing pending for this store']);
        $this->assertNotSettled($orderA, $txA);

        // the store's own signed event settles it — once
        $this->storeWebhook($storeA->id, $body, $this->stripeSigned($body, 'whsec_store_a'))->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSettledOnce($orderA, $txA);
        $this->storeWebhook($storeA->id, $body, $this->stripeSigned($body, 'whsec_store_a'))->assertOk()->assertJson(['message' => 'duplicate']);
        $this->assertCreditedOnce($txA);
        $this->assertNotSettled($orderB, $txB);
    }

    public function test_a_store_without_a_webhook_secret_is_refused(): void
    {
        [$store, $order, $tx] = $this->order(['secret_key' => 'sk_test_a']);
        $tx->update(['payment_id' => 'cs_X']);
        $body = $this->sessionEvent('evt_X', 'cs_X');

        $this->storeWebhook($store->id, $body, $this->stripeSigned($body, ''))->assertStatus(400);
        $this->assertNotSettled($order, $tx);
    }

    public function test_a_stores_own_paypal_webhook_is_verified_with_its_own_webhook_id_and_app(): void
    {
        $this->gw = 'paypal';
        $this->setUpGateway();
        config(['settings.paypal_api_client_id' => 'platform-client', 'settings.paypal_api_secret' => 'platform-secret', 'settings.paypal_webhook_id' => 'WH-PLATFORM']);
        [$store, $order, $tx] = $this->order(['api_client_id' => 'store-client', 'api_secret' => 'store-secret']);
        $tx->update(['payment_id' => 'ORD-S1']);
        $headers = ['HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA', 'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/C',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'TX-S', 'HTTP_PAYPAL_TRANSMISSION_SIG' => 'c2ln', 'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601String()];
        $body = json_encode(['id' => 'WH-EVT-S1', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORD-S1']]);
        $captured = ['id' => 'ORD-S1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [
            ['id' => 'CAP-S1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'SAR', 'value' => '100.00']]]]]]];
        $verified = fn (ClientRequest $r) => ['verification_status' => $r['webhook_id'] === 'WH-STORE' ? 'SUCCESS' : 'FAILURE'];
        $this->fake([
            'POST /v1/oauth2/token' => fn (ClientRequest $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('store-client:store-secret'))
                ? ['access_token' => 'store-token'] : ['access_token' => 'platform-token'],
            'POST /v1/notifications/verify-webhook-signature' => $verified,
            'POST /v2/checkout/orders/ORD-S1/capture' => $captured,
        ]);

        // no store webhook id: refused (the platform's id is never used for a store route)
        $this->storeWebhook($store->id, $body, $headers)->assertStatus(400);
        Http::assertNothingSent();

        StoreOption::updateStoreOption($store->id, 'restaurant_paypal_webhook_id', 'WH-STORE');
        $this->storeWebhook($store->id, $body, $headers)->assertOk();
        $this->assertSettledOnce($order, $tx);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/verify-webhook-signature') && $r['webhook_id'] === 'WH-STORE'
            && $r->hasHeader('Authorization', 'Bearer store-token'));

        $this->storeWebhook($store->id, $body, $headers)->assertOk()->assertJson(['message' => 'duplicate']);
        $this->assertCreditedOnce($tx);
    }

    public function test_a_gateway_without_store_webhooks_answers_404(): void
    {
        $this->gw = 'tap';
        $this->setUpGateway();
        [$store] = $this->order(['secret_key' => 'sk_test_t']);
        $this->storeWebhook($store->id, '{}')->assertNotFound();
    }
}
