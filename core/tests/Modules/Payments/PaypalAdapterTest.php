<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaypalController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06a — PayPal adapter over a faked HTTP client (no network, fake keys).
 */
class PaypalAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        PaymentGateway::create(['payment_folder' => 'paypal', 'payment_title' => 'PayPal', 'payment_install' => '1']);
        config([
            'settings.paypal_api_client_id' => 'fake-platform-client',
            'settings.paypal_api_secret' => 'fake-platform-secret',
            'settings.paypal_sandbox_mode' => 'Yes',
            'settings.paypal_payment_mode' => 'one_time',
            'settings.currency_code' => 'USD',
            'settings.site_title' => 'juwri',
        ]);
    }

    /** @var array<string, mixed> */
    private array $routes = [];

    private bool $faked = false;

    /**
     * Answer PayPal calls from `$routes` ("METHOD path-fragment" => body, or
     * ['__status' => code, 'body' => body]). Later calls replace the routes
     * (Http::fake callbacks stack, so the fake itself is registered once).
     *
     * @param  array<string, mixed>  $routes
     */
    private function fakePaypal(array $routes): void
    {
        $this->routes = $routes;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (ClientRequest $request) {
            $routes = $this->routes;
            if (str_ends_with($request->url(), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 300]);
            }
            foreach ($routes as $suffix => $answer) {
                [$method, $path] = explode(' ', $suffix, 2);
                if ($request->method() === $method && str_contains($request->url(), $path)) {
                    return is_array($answer) && array_key_exists('__status', $answer)
                        ? Http::response($answer['body'], $answer['__status'])
                        : Http::response($answer);
                }
            }

            return Http::response(['message' => 'unexpected '.$request->method().' '.$request->url()], 500);
        });
    }

    private function orderTx(float $amount, string $currency): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('paypal', $amount, ['api_client_id' => 'fake-store-client', 'api_secret' => 'fake-store-secret'], $currency);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return [$store, $order, $tx];
    }

    public function test_initiate_order_uses_store_app_sandbox_and_currency_decimals(): void
    {
        [$store, , $tx] = $this->orderTx(12.5, 'KWD');
        $this->fakePaypal(['POST /v2/checkout/orders' => ['id' => 'ORDER-1', 'links' => [
            ['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1'],
            ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.test/checkoutnow?token=ORDER-1'],
        ]]]);

        $response = PaypalController::pay($tx);

        $this->assertSame('https://www.sandbox.paypal.test/checkoutnow?token=ORDER-1', $response->getTargetUrl());
        $this->assertSame('ORDER-1', $tx->fresh()->payment_id);
        Http::assertSent(fn (ClientRequest $r) => str_starts_with($r->url(), 'https://api-m.sandbox.paypal.com/v1/oauth2/token')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('fake-store-client:fake-store-secret')));
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/v2/checkout/orders')
            && $r['intent'] === 'CAPTURE'
            && $r['purchase_units'][0]['amount'] === ['currency_code' => 'KWD', 'value' => '12.500']
            && $r['application_context']['cancel_url'] === route('publicView', $store->slug)
            && str_contains($r['application_context']['return_url'], 'pay_mode=one_time'));
    }

    public function test_initiate_refused_order_goes_back_with_the_reason(): void
    {
        [, $tx] = $this->makePlanTransaction(10.0, 'USD', 'wallet_topup');
        $this->fakePaypal(['POST /v2/checkout/orders' => ['__status' => 422, 'body' => ['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The requested action could not be performed.']]]);

        $response = PaypalController::pay($tx);

        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('The requested action could not be performed.', (string) session('app_alert_message'));
        $this->assertNull($tx->fresh()->payment_id);
    }

    public function test_initiate_recurring_creates_product_plan_subscription_and_keeps_checkout_row(): void
    {
        config(['settings.paypal_payment_mode' => 'recurring']);
        [, $tx] = $this->makePlanTransaction(9.0, 'USD', 'membership');
        $tx->update(['product_id' => 7, 'frequency' => 'YEARLY', 'product_name' => 'Pro']);
        $this->fakePaypal([
            'GET /v1/catalogs/products/product_7' => ['__status' => 404, 'body' => ['name' => 'RESOURCE_NOT_FOUND']],
            'POST /v1/catalogs/products' => ['id' => 'product_7'],
            'POST /v1/billing/plans' => ['id' => 'P-FAKE1'],
            'POST /v1/billing/subscriptions' => ['id' => 'I-FAKE1', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.test/webapps/billing/subscriptions?ba_token=BA-1']]],
        ]);

        $response = PaypalController::pay($tx);

        $this->assertSame('https://www.sandbox.paypal.test/webapps/billing/subscriptions?ba_token=BA-1', $response->getTargetUrl());
        $fresh = Transaction::find($tx->id);
        $this->assertNotNull($fresh);
        $this->assertSame('I-FAKE1', $fresh->payment_id);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/v1/billing/plans')
            && $r['billing_cycles'][0]['frequency'] === ['interval_unit' => 'DAY', 'interval_count' => 365]
            && $r['billing_cycles'][0]['pricing_scheme']['fixed_price'] === ['value' => '9.00', 'currency_code' => 'USD']);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/v1/billing/subscriptions')
            && $r['plan_id'] === 'P-FAKE1'
            && $r['custom_id'] === (string) $tx->id
            && strlen((string) $r['custom_id']) <= 127);
    }

    public function test_return_completed_capture_settles_once(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $order, $tx] = $this->orderTx(20.0, 'USD');
        $tx->update(['payment_id' => 'ORDER-2']);
        $this->fakePaypal(['POST /v2/checkout/orders/ORDER-2/capture' => ['id' => 'ORDER-2', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-2', 'amount' => ['value' => '20.00', 'currency_code' => 'USD']]]]]]]]);

        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORDER-2&PayerID=FAKE')->assertOk();
        $this->assertSettledOnce($order, $tx);
        $this->assertSame('paypal', $tx->fresh()->transaction_gatway);

        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORDER-2')->assertRedirect(route('subscription'));
        $this->assertSettledOnce($order, $tx);
    }

    public function test_return_declined_or_short_capture_does_not_settle(): void
    {
        [, $order, $tx] = $this->orderTx(20.0, 'USD');
        $tx->update(['payment_id' => 'ORDER-3']);
        $this->fakePaypal(['POST /v2/checkout/orders/ORDER-3/capture' => ['id' => 'ORDER-3', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-3', 'amount' => ['value' => '2.00', 'currency_code' => 'USD']]]]]]]]);
        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORDER-3')->assertRedirect();
        $this->assertNotSettled($order, $tx);

        [, $order2, $tx2] = $this->orderTx(20.0, 'USD');
        $tx2->update(['payment_id' => 'ORDER-4']);
        $this->fakePaypal(['POST /v2/checkout/orders/ORDER-4/capture' => ['__status' => 422, 'body' => ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]]]]);
        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORDER-4')->assertRedirect();
        $this->assertNotSettled($order2, $tx2);
        $this->assertSame('error', session('app_alert_type'));
    }

    /** WI-15-sec: a COMPLETED order whose capture is PENDING, or one without a captured amount, does not settle. */
    public function test_return_pending_capture_or_missing_amount_does_not_settle(): void
    {
        [, $order, $tx] = $this->orderTx(20.0, 'USD');
        $tx->update(['payment_id' => 'ORDER-6']);
        $this->fakePaypal(['POST /v2/checkout/orders/ORDER-6/capture' => ['id' => 'ORDER-6', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-6', 'status' => 'PENDING', 'amount' => ['value' => '20.00', 'currency_code' => 'USD']]]]]]]]);
        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORDER-6')->assertRedirect();
        $this->assertNotSettled($order, $tx);

        [, $order2, $tx2] = $this->orderTx(20.0, 'USD');
        $tx2->update(['payment_id' => 'ORDER-7']);
        $this->fakePaypal(['POST /v2/checkout/orders/ORDER-7/capture' => ['id' => 'ORDER-7', 'status' => 'COMPLETED']]);
        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORDER-7')->assertRedirect();
        $this->assertNotSettled($order2, $tx2);
    }

    private function saleEvent(): string
    {
        return json_encode(['id' => 'WH-1', 'event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => ['id' => 'SALE-1', 'billing_agreement_id' => 'I-FAKE9']]);
    }

    private function postWebhook(string $payload, array $headers = []): TestResponse
    {
        return $this->call('POST', route('webhook', 'paypal'), [], [], [], array_merge(['CONTENT_TYPE' => 'application/json'], $headers), $payload);
    }

    private function transmissionHeaders(): array
    {
        return [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-fake',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'T-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'c2lnbmF0dXJl',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601String(),
        ];
    }

    public function test_webhook_signature_valid_and_invalid(): void
    {
        config(['settings.paypal_webhook_id' => 'WH-ID-FAKE']);

        // No transmission headers: refused before PayPal is asked anything.
        $this->fakePaypal([]);
        $this->postWebhook($this->saleEvent())->assertStatus(400);
        Http::assertSentCount(0);

        // PayPal says the signature does not verify.
        $this->fakePaypal(['POST /v1/notifications/verify-webhook-signature' => ['verification_status' => 'FAILURE']]);
        $this->postWebhook($this->saleEvent(), $this->transmissionHeaders())->assertStatus(400);
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'paypal: webhook rejected (invalid signature)']);

        // Verified: the subscription is re-read and its custom_id handed to processWebhook
        // (unknown account in it → processWebhook answers 400, nothing created).
        $this->fakePaypal([
            'POST /v1/notifications/verify-webhook-signature' => ['verification_status' => 'SUCCESS'],
            'GET /v1/payments/sale/SALE-1' => $this->heldSale(),
            'GET /v1/billing/subscriptions/I-FAKE9' => ['id' => 'I-FAKE9', 'create_time' => '2026-11-15T23:59:59Z', 'custom_id' => http_build_query(['user_id' => 999999, 'product_id' => 1, 'transaction_method' => 'membership'])],
        ]);
        $this->postWebhook($this->saleEvent(), $this->transmissionHeaders())->assertStatus(400)->assertJson(['status' => 400]);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/verify-webhook-signature')
            && $r['webhook_id'] === 'WH-ID-FAKE' && $r['transmission_id'] === 'T-1' && $r['webhook_event']['id'] === 'WH-1');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/v1/billing/subscriptions/I-FAKE9'));
    }

    public function test_webhook_without_configured_id_and_other_events(): void
    {
        // Phase 4 item 18: no webhook id configured = unsigned = every notification refused (before: processed, with
        // only the sale re-read guarding the money path; unreadable bodies answered 200).
        $this->fakePaypal([]);
        $this->postWebhook('not json')->assertStatus(400);
        $this->postWebhook(json_encode(['event_type' => 'BILLING.SUBSCRIPTION.CREATED']), $this->transmissionHeaders())->assertStatus(400);
        $this->postWebhook($this->saleEvent(), $this->transmissionHeaders())->assertStatus(400);
        Http::assertSentCount(0);
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'paypal: webhook rejected (no webhook id configured (unsigned notifications are refused))']);

        // signed: other events are acknowledged and do nothing
        config(['settings.paypal_webhook_id' => 'WH-ID-FAKE']);
        $this->fakePaypal(['POST /v1/notifications/verify-webhook-signature' => ['verification_status' => 'SUCCESS']]);
        $this->postWebhook(json_encode(['id' => 'WH-2', 'event_type' => 'BILLING.SUBSCRIPTION.CREATED']), $this->transmissionHeaders())->assertOk();
    }

    private function heldSale(array $overrides = []): array
    {
        return $overrides + ['id' => 'SALE-1', 'state' => 'completed', 'billing_agreement_id' => 'I-FAKE9', 'amount' => ['total' => '9.00', 'currency' => 'USD']];
    }

    /**
     * WI-15-sec: even a signed notification is not trusted for money — the sale itself is read back from PayPal. An
     * invented sale id, a sale of another subscription or a sale that is not completed never reaches processWebhook.
     */
    public function test_a_signed_webhook_settles_only_a_sale_paypal_confirms(): void
    {
        config(['settings.paypal_webhook_id' => 'WH-ID-FAKE']);
        $custom = ['id' => 'I-FAKE9', 'create_time' => '2026-11-15T23:59:59Z', 'custom_id' => http_build_query(['user_id' => 999999, 'product_id' => 1, 'transaction_method' => 'membership'])];
        $verified = ['POST /v1/notifications/verify-webhook-signature' => ['verification_status' => 'SUCCESS']];

        foreach ([
            ['__status' => 404, 'body' => ['name' => 'INVALID_RESOURCE_ID']],
            $this->heldSale(['billing_agreement_id' => 'I-OTHER']),
            $this->heldSale(['state' => 'pending']),
        ] as $held) {
            $this->fakePaypal($verified + ['GET /v1/payments/sale/SALE-1' => $held, 'GET /v1/billing/subscriptions/I-FAKE9' => $custom]);
            $this->postWebhook($this->saleEvent(), $this->transmissionHeaders())->assertStatus(400);
        }
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/v1/billing/subscriptions/'));
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'paypal: webhook rejected (sale not confirmed by PayPal)']);

        // A confirmed sale goes on to the subscription re-read.
        $this->fakePaypal($verified + ['GET /v1/payments/sale/SALE-1' => $this->heldSale(), 'GET /v1/billing/subscriptions/I-FAKE9' => $custom]);
        $this->postWebhook($this->saleEvent(), $this->transmissionHeaders())->assertStatus(400)->assertJson(['status' => 400]);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/v1/billing/subscriptions/I-FAKE9'));
    }

    public function test_refund_refunds_the_orders_capture(): void
    {
        [, $tx] = $this->makePlanTransaction(15.0, 'USD', 'wallet_topup');
        $tx->update(['payment_id' => 'ORDER-5', 'status' => Transaction::STATUS_SUCCESS]);
        $this->fakePaypal([
            'GET /v2/checkout/orders/ORDER-5' => ['id' => 'ORDER-5', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-5']]]]]],
            'POST /v2/payments/captures/CAP-5/refund' => ['id' => 'REF-5', 'status' => 'COMPLETED'],
        ]);

        $this->assertSame(['ok' => true, 'reference' => 'REF-5', 'message' => null], PaypalController::adapter()->refund($tx->fresh(), 5.0));
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/captures/CAP-5/refund') && $r['amount'] === ['value' => '5.00', 'currency_code' => 'USD']);
    }
}
