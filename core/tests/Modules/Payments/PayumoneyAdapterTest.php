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
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PayumoneyController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06b — PayU ("payumoney") adapter over a faked HTTP client (no network, fake keys).
 */
class PayumoneyAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const STORE_KEY = 'fake_store_second_key';

    private const PLATFORM_KEY = 'fake_platform_second_key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'payumoney', 'payment_title' => 'PayU', 'payment_install' => '1']);
        config([
            'settings.payumoney_sandbox_mode' => '0',
            'settings.payumoney_merchant_pos_id' => '300746',
            'settings.payumoney_signature_key' => self::PLATFORM_KEY,
            'settings.payumoney_oauth_client_id' => '300746',
            'settings.payumoney_oauth_client_secret' => 'fake_platform_client_secret',
            'settings.currency_code' => 'PLN',
        ]);
    }

    private function orderTx(float $amount = 100.0): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('payumoney', $amount, [
            'merchant_pos_id' => '145227', 'signature_key' => self::STORE_KEY,
        ], 'PLN');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'PLN');

        return [$store, $order, $tx];
    }

    /** @return array<string, mixed> */
    private function payuOrder(Transaction $tx, string $status = 'COMPLETED', string $amount = '10000', string $currency = 'PLN'): array
    {
        return ['orderId' => 'PAYU'.$tx->id, 'extOrderId' => (string) $tx->id, 'totalAmount' => $amount, 'currencyCode' => $currency, 'status' => $status];
    }

    private function notify(string $payload, ?string $key, string $algorithm = 'MD5'): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($key !== null) {
            $server['HTTP_OPENPAYU_SIGNATURE'] = 'sender=checkout;signature='.hash(strtolower(str_replace('-', '', $algorithm)), $payload.$key).';algorithm='.$algorithm.';content=DOCUMENT';
        }

        return $this->call('POST', url('webhook/payumoney'), [], [], [], $server, $payload);
    }

    public function test_initiate_order_uses_the_store_pos_and_redirects_to_payu(): void
    {
        [$store, , $tx] = $this->orderTx(100.0);
        Http::fake(['secure.snd.payu.com/api/v2_1/orders' => Http::response([
            'status' => ['statusCode' => 'SUCCESS'], 'redirectUri' => 'https://merch-prod.snd.payu.com/pay/?orderId=X1', 'orderId' => 'X1',
        ], 302)]);

        $response = PayumoneyController::pay($tx);

        $this->assertSame('https://merch-prod.snd.payu.com/pay/?orderId=X1', $response->headers->get('Location'));
        $this->assertSame('X1', $tx->fresh()->payment_id);
        Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('145227:'.self::STORE_KEY))
            && $r['merchantPosId'] === '145227' && $r['totalAmount'] === '10000' && $r['currencyCode'] === 'PLN'
            && $r['extOrderId'] === (string) $tx->id && $r['notifyUrl'] === url('webhook/payumoney').'?order_id='.$tx->id);
    }

    public function test_initiate_platform_uses_oauth_and_reports_a_refusal(): void
    {
        [, $tx] = $this->makePlanTransaction(10.0, 'PLN');
        Http::fake([
            'secure.payu.com/pl/standard/user/oauth/authorize' => Http::response(['access_token' => 'tok_fake', 'expires_in' => 43199]),
            'secure.payu.com/api/v2_1/orders' => Http::response(['status' => ['statusCode' => 'ERROR_VALUE_INVALID', 'statusDesc' => 'Invalid currency']], 400),
        ]);

        $this->assertTrue(PayumoneyController::pay($tx)->isRedirect());
        $this->assertSame('Invalid currency', session('app_alert_message'));
        $this->assertNull($tx->fresh()->payment_id);
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/api/v2_1/orders') && $r->hasHeader('Authorization', 'Bearer tok_fake'));
    }

    public function test_webhook_signature_status_amount_and_replay(): void
    {
        [, $order, $tx] = $this->orderTx(100.0);
        $tx->update(['payment_id' => 'PAYU'.$tx->id]);
        Http::fake(['secure.snd.payu.com/api/v2_1/orders/*' => Http::response(['orders' => [$this->payuOrder($tx)]])]);

        $completed = json_encode(['order' => $this->payuOrder($tx)]);
        $this->notify($completed, null)->assertStatus(400);
        $this->notify($completed, self::PLATFORM_KEY)->assertStatus(400);
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'payumoney: webhook rejected (invalid signature)']);

        // PayU notifies PENDING first: acknowledged, not settled (the old driver settled here).
        $this->notify(json_encode(['order' => $this->payuOrder($tx, 'PENDING')]), self::STORE_KEY)->assertOk();
        // Signed but short.
        $this->notify(json_encode(['order' => $this->payuOrder($tx, 'COMPLETED', '100')]), self::STORE_KEY)->assertOk();
        $this->assertNotSettled($order, $tx);

        $this->notify($completed, self::STORE_KEY, 'SHA-256')->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSettledOnce($order, $tx);

        $this->notify($completed, self::STORE_KEY)->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_return_settles_a_completed_order_and_otherwise_shows_processing(): void
    {
        [$store, $order, $tx] = $this->orderTx(100.0);
        $tx->update(['payment_id' => 'PAYU'.$tx->id, 'details' => ['whatsapp_url' => 'https://wa.me/1?text=</script><script>x']]);
        Http::fake(['secure.snd.payu.com/api/v2_1/orders/*' => Http::sequence()
            ->push(['orders' => [$this->payuOrder($tx, 'PENDING')]])
            ->push(['orders' => [$this->payuOrder($tx)]])]);

        $pending = $this->get(route('ipn', 'payumoney').'?order_id='.$tx->id)->assertOk();
        $this->assertStringContainsString(route('publicView', $store->slug).'?return=success', str_replace('\/', '/', $pending->getContent()));
        $this->assertStringNotContainsString('</script><script>x', $pending->getContent());
        $this->assertNotSettled($order, $tx);

        $this->get(route('ipn', 'payumoney').'?order_id='.$tx->id)->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_return_for_a_plan_without_confirmation_says_processing(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $tx] = $this->makePlanTransaction(10.0, 'PLN');
        $this->get(route('ipn', 'payumoney').'?order_id='.$tx->id)->assertRedirect(route('subscription'));
        $this->assertSame('success', session('app_alert_type'));
        $this->get(route('ipn', 'payumoney').'?order_id=99999999')->assertRedirect();
        $this->assertSame('error', session('app_alert_type'));
    }

    public function test_refund(): void
    {
        [, $tx] = $this->makePlanTransaction(50.0, 'PLN', 'wallet_topup');
        $tx->update(['status' => Transaction::STATUS_SUCCESS, 'payment_id' => 'PAYU9']);
        Http::fake([
            'secure.payu.com/pl/standard/user/oauth/authorize' => Http::response(['access_token' => 'tok_fake', 'expires_in' => 43199]),
            'secure.payu.com/api/v2_1/orders/PAYU9/refunds' => Http::response(['status' => ['statusCode' => 'SUCCESS'], 'refund' => ['refundId' => 'R77']]),
        ]);

        $this->assertSame(['ok' => true, 'reference' => 'R77', 'message' => null], PayumoneyController::adapter()->refund($tx->fresh(), 20.0));
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/refunds') && $r['refund']['amount'] === '2000');
    }
}
