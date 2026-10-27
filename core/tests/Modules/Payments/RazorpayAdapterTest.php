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
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\RazorpayController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06a — Razorpay adapter over a faked HTTP client (no network, fake keys).
 */
class RazorpayAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const STORE_SECRET = 'fake_store_secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'razorpay', 'payment_title' => 'Razorpay', 'payment_install' => '1']);
        config([
            'settings.razorpay_api_key' => 'rzp_test_fake_platform',
            'settings.razorpay_secret_key' => 'fake_platform_secret',
            'settings.razorpay_webhook_secret' => 'fake_webhook_secret',
            'settings.currency_code' => 'INR',
        ]);
    }

    private function orderTx(float $amount = 250.0): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('razorpay', $amount, ['api_key' => 'rzp_test_fake_store', 'secret_key' => self::STORE_SECRET], 'INR');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'INR');

        return [$store, $order, $tx];
    }

    public function test_initiate_order_creates_a_razorpay_order_and_renders_the_widget(): void
    {
        [$store, , $tx] = $this->orderTx(250.0);
        $tx->update(['details' => ['customer_name' => 'Asha']]);
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_FAKE1', 'amount' => 25000, 'currency' => 'INR', 'status' => 'created'])]);

        $response = TestResponse::fromBaseResponse(RazorpayController::pay($tx->fresh()));

        $response->assertOk()->assertViewIs('owner.billing.gateways.razorpay')
            ->assertViewHas('details', fn ($d) => $d['order_id'] === 'order_FAKE1' && $d['amount'] === 25000
                && $d['key'] === 'rzp_test_fake_store' && $d['prefill.name'] === 'Asha')
            ->assertViewHas('cancel_url', route('publicView', $store->slug));
        $this->assertSame('order_FAKE1', $tx->fresh()->payment_id);
        Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_fake_store:'.self::STORE_SECRET))
            && $r['amount'] === 25000 && $r['currency'] === 'INR' && $r['receipt'] === (string) $tx->product_id && $r['payment_capture'] === 1);
    }

    public function test_initiate_for_a_guest_outside_orders_does_not_crash(): void
    {
        [, $tx] = $this->makePlanTransaction(99.0, 'INR', 'gift_card_purchase');
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_FAKE2'])]);

        $response = TestResponse::fromBaseResponse(RazorpayController::pay($tx));

        $response->assertOk()->assertViewHas('details', fn ($d) => $d['prefill.email'] === '' && $d['key'] === 'rzp_test_fake_platform');
    }

    public function test_initiate_provider_error_goes_back(): void
    {
        [, $tx] = $this->makePlanTransaction(99.0, 'INR', 'wallet_topup');
        Http::fake(['api.razorpay.com/*' => Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Authentication failed']], 401)]);

        $this->assertTrue(RazorpayController::pay($tx)->isRedirect());
        $this->assertSame('Authentication failed', session('app_alert_message'));
    }

    public function test_return_valid_signature_settles_and_tampered_does_not(): void
    {
        [, $order, $tx] = $this->orderTx();
        $tx->update(['payment_id' => 'order_FAKE3']);
        $store = ['razorpay_order_id' => 'order_FAKE3', 'razorpay_payment_id' => 'pay_FAKE3'];

        $this->post(route('ipn', 'razorpay'), $store + ['razorpay_signature' => str_repeat('0', 64)])->assertRedirect();
        $this->assertNotSettled($order, $tx);

        $this->post(route('ipn', 'razorpay'), $store + ['razorpay_signature' => hash_hmac('sha256', 'order_FAKE3|pay_FAKE3', self::STORE_SECRET)])->assertOk();
        $this->assertSettledOnce($order, $tx);
        $this->assertSame('pay_FAKE3', $tx->fresh()->payment_id);
        Http::assertNothingSent();
    }

    private function webhook(string $payload, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_RAZORPAY_SIGNATURE'] = $signature;
        }

        return $this->call('POST', route('webhook', 'razorpay'), [], [], [], $server, $payload);
    }

    public function test_webhook_signature_valid_and_invalid(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        [, $tx] = $this->makePlanTransaction(99.0, 'INR', 'wallet_topup');
        $tx->update(['payment_id' => 'order_FAKE4']);
        $payload = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => 'pay_FAKE4', 'order_id' => 'order_FAKE4', 'amount' => 9900, 'currency' => 'INR', 'status' => 'captured',
        ]]]]);

        $this->webhook($payload, null)->assertStatus(400);
        $this->webhook($payload, hash_hmac('sha256', $payload, 'other_secret'))->assertStatus(400);
        $this->assertNull($tx->fresh()->status);
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'razorpay: webhook rejected (invalid signature)']);

        $this->webhook($payload, hash_hmac('sha256', $payload, 'fake_webhook_secret'))->assertOk();
        $this->assertSame(Transaction::STATUS_SUCCESS, $tx->fresh()->status);
        $this->assertSame('pay_FAKE4', $tx->fresh()->payment_id);
        $this->assertNotNull($tx->fresh()->credited_at);

        // Replay: nothing pending under that order id any more.
        $this->webhook($payload, hash_hmac('sha256', $payload, 'fake_webhook_secret'))->assertOk();
    }

    public function test_webhook_short_payment_does_not_settle_and_unconfigured_secret_is_a_noop(): void
    {
        [, $tx] = $this->makePlanTransaction(99.0, 'INR', 'wallet_topup');
        $tx->update(['payment_id' => 'order_FAKE5']);
        $payload = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => 'pay_FAKE5', 'order_id' => 'order_FAKE5', 'amount' => 100, 'currency' => 'INR', 'status' => 'captured',
        ]]]]);
        $this->webhook($payload, hash_hmac('sha256', $payload, 'fake_webhook_secret'))->assertOk();
        $this->assertNull($tx->fresh()->status);

        config(['settings.razorpay_webhook_secret' => '']);
        $this->webhook($payload, 'anything')->assertOk()->assertJson(['message' => 'webhook not configured']);
        $this->assertNull($tx->fresh()->status);
    }

    public function test_refund(): void
    {
        [, $tx] = $this->makePlanTransaction(99.0, 'INR', 'wallet_topup');
        $tx->update(['payment_id' => 'pay_FAKE6', 'status' => Transaction::STATUS_SUCCESS]);
        Http::fake(['api.razorpay.com/v1/payments/pay_FAKE6/refund' => Http::response(['id' => 'rfnd_FAKE6', 'status' => 'processed'])]);

        $this->assertSame(['ok' => true, 'reference' => 'rfnd_FAKE6', 'message' => null], RazorpayController::adapter()->refund($tx->fresh(), 10.0));
        Http::assertSent(fn (ClientRequest $r) => $r['amount'] === 1000);
    }
}
