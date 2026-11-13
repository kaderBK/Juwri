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
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaystackController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06a — Paystack adapter over a faked HTTP client (no network, fake keys).
 * The underpayment / currency-swap guards also have PaystackAmountVerifyTest.
 */
class PaystackAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const STORE_SECRET = 'sk_test_fake_store';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'paystack', 'payment_title' => 'Paystack', 'payment_install' => '1']);
        config([
            'settings.paystack_public_key' => 'pk_test_fake_platform',
            'settings.paystack_secret_key' => 'sk_test_fake_platform',
            'settings.currency_code' => 'NGN',
        ]);
    }

    private function orderTx(float $amount = 100.0): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('paystack', $amount, ['public_key' => 'pk_test_fake_store', 'secret_key' => self::STORE_SECRET], 'NGN');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'NGN');

        return [$store, $order, $tx];
    }

    public function test_initiate_order_renders_the_inline_widget(): void
    {
        [$store, , $tx] = $this->orderTx(100.0);
        request()->merge(['email' => 'diner@example.test']);

        $response = TestResponse::fromBaseResponse(PaystackController::pay($tx));

        $response->assertOk()->assertViewIs('owner.billing.gateways.paystack')
            ->assertViewHas('details', fn ($d) => $d['key'] === 'pk_test_fake_store' && $d['amount'] === 10000
                && $d['email'] === 'diner@example.test' && $d['ref'] === (string) $tx->id && $d['currency'] === 'NGN')
            ->assertViewHas('cancel_url', route('publicView', $store->slug));
        $this->assertNull($tx->fresh()->payment_id);
        Http::assertNothingSent();
    }

    public function test_initiate_requires_an_email_for_orders_and_guests(): void
    {
        [, , $tx] = $this->orderTx();
        request()->merge(['email' => 'not-an-email']);
        $this->assertTrue(PaystackController::pay($tx)->isRedirect());
        $this->assertSame('error', session('app_alert_type'));

        // A guest deposit/gift card no longer 500s on a missing account; it asks for the e-mail too.
        [, $gift] = $this->makePlanTransaction(50.0, 'NGN', 'gift_card_purchase');
        request()->merge(['email' => '']);
        $this->assertTrue(PaystackController::pay($gift)->isRedirect());
        request()->merge(['email' => 'guest@example.test']);
        TestResponse::fromBaseResponse(PaystackController::pay($gift))->assertOk()
            ->assertViewHas('details', fn ($d) => $d['email'] === 'guest@example.test' && $d['key'] === 'pk_test_fake_platform');
    }

    public function test_return_success_settles_once_and_failure_does_not(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $order, $tx] = $this->orderTx(100.0);
        [, $order2, $tx2] = $this->orderTx(100.0);
        $charges = [
            $tx->id => ['status' => 'failed', 'amount' => 10000, 'currency' => 'NGN', 'gateway_response' => 'Declined'],
            $tx2->id => ['status' => 'success', 'amount' => 10000, 'currency' => 'NGN', 'gateway_response' => 'Successful'],
        ];
        Http::fake(fn (ClientRequest $r) => Http::response(['status' => true, 'data' => $charges[(int) basename($r->url())] ?? null]));

        $this->post(route('ipn', 'paystack'), ['reference' => $tx->id])->assertRedirect();
        $this->assertNotSettled($order, $tx);
        $this->assertStringContainsString('Declined', (string) session('app_alert_message'));

        $this->post(route('ipn', 'paystack'), ['reference' => $tx2->id])->assertOk();
        $this->assertSettledOnce($order2, $tx2);
        Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('Authorization', 'Bearer '.self::STORE_SECRET));

        $this->post(route('ipn', 'paystack'), ['reference' => $tx2->id])->assertRedirect(route('subscription'));
        $this->assertSettledOnce($order2, $tx2);
    }

    private function webhook(string $payload, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_PAYSTACK_SIGNATURE'] = $signature;
        }

        return $this->call('POST', route('webhook', 'paystack'), [], [], [], $server, $payload);
    }

    public function test_webhook_signature_valid_and_invalid(): void
    {
        [, $order, $tx] = $this->orderTx(100.0);
        $payload = json_encode(['event' => 'charge.success', 'data' => ['reference' => (string) $tx->id, 'status' => 'success', 'amount' => 10000, 'currency' => 'NGN']]);

        $this->webhook($payload, null)->assertStatus(400);
        $this->webhook($payload, hash_hmac('sha512', $payload, 'sk_test_fake_platform'))->assertStatus(400);
        $this->assertNotSettled($order, $tx);
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'paystack: webhook rejected (invalid signature)']);

        $this->webhook($payload, hash_hmac('sha512', $payload, self::STORE_SECRET))->assertOk();
        $this->assertSettledOnce($order, $tx);

        // A redelivery is still checked against the store's key, then ignored.
        $this->webhook($payload, hash_hmac('sha512', $payload, self::STORE_SECRET))->assertOk();
        $this->webhook($payload, hash_hmac('sha512', $payload, 'sk_test_fake_platform'))->assertStatus(400);
        $this->assertSettledOnce($order, $tx);
    }

    public function test_webhook_underpaid_charge_does_not_settle(): void
    {
        [, $order, $tx] = $this->orderTx(100.0);
        $payload = json_encode(['event' => 'charge.success', 'data' => ['reference' => (string) $tx->id, 'status' => 'success', 'amount' => 100, 'currency' => 'NGN']]);

        $this->webhook($payload, hash_hmac('sha512', $payload, self::STORE_SECRET))->assertOk();
        $this->assertNotSettled($order, $tx);
    }

    public function test_refund(): void
    {
        [, $tx] = $this->makePlanTransaction(50.0, 'NGN', 'wallet_topup');
        $tx->update(['status' => Transaction::STATUS_SUCCESS]);
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 3018284, 'status' => 'pending']])]);

        $this->assertSame(['ok' => true, 'reference' => '3018284', 'message' => null], PaystackController::adapter()->refund($tx->fresh(), 20.0));
        Http::assertSent(fn (ClientRequest $r) => $r['transaction'] === (string) $tx->id && $r['amount'] === 2000);
    }
}
