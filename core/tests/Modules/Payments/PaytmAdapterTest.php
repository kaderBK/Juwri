<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaytmController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\PaytmChecksum;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06b — Paytm adapter over a faked HTTP client (no network, fake keys).
 */
class PaytmAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const STORE_KEY = 'fakeStoreKey0001';

    private const PLATFORM_KEY = 'fakePlatfKey0002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'paytm', 'payment_title' => 'Paytm', 'payment_install' => '1']);
        config([
            'settings.PAYTM_MERCHANT_MID' => 'PLATFORMMID01',
            'settings.PAYTM_MERCHANT_KEY' => self::PLATFORM_KEY,
            'settings.PAYTM_MERCHANT_WEBSITE' => 'WEBSTAGING',
            'settings.PAYTM_ENVIRONMENT' => 'PROD',
            'settings.currency_code' => 'INR',
        ]);
    }

    private function orderTx(float $amount = 100.0): array
    {
        return $this->makeOrderTransaction('paytm', $amount, [
            'merchant_mid' => 'STOREMID0001', 'merchant_key' => self::STORE_KEY, 'merchant_website' => 'WEBSTAGING',
        ], 'INR');
    }

    /** @return array<string, string> the callback Paytm posts, signed with $key */
    private function paytmPost(Transaction $tx, string $key, string $status = 'TXN_SUCCESS', string $amount = '100.00'): array
    {
        $fields = [
            'ORDERID' => (string) $tx->id, 'MID' => 'STOREMID0001', 'TXNID' => 'TXN'.$tx->id, 'TXNAMOUNT' => $amount,
            'CURRENCY' => 'INR', 'STATUS' => $status, 'RESPCODE' => $status === 'TXN_SUCCESS' ? '01' : '227',
            'RESPMSG' => $status === 'TXN_SUCCESS' ? 'Txn Success' : 'Bank declined', 'BANKNAME' => 'HDFC', 'PAYMENTMODE' => 'UPI',
        ];
        $fields['CHECKSUMHASH'] = PaytmChecksum::generate($fields, $key);

        return $fields;
    }

    private function fakeStatus(string $resultStatus = 'TXN_SUCCESS', string $amount = '100.00'): void
    {
        Http::fake(['*/v3/order/status' => fn (ClientRequest $r) => Http::response(['body' => [
            'resultInfo' => ['resultStatus' => $resultStatus, 'resultMsg' => $resultStatus],
            'orderId' => $r->data()['body']['orderId'], 'txnId' => 'TXN'.$r->data()['body']['orderId'], 'txnAmount' => $amount,
        ]])]);
    }

    public function test_checksum_round_trip_and_tamper(): void
    {
        $fields = ['MID' => 'M1', 'ORDERID' => '7', 'TXNAMOUNT' => '10.00'];
        $sum = PaytmChecksum::generate($fields, self::STORE_KEY);

        $this->assertTrue(PaytmChecksum::verify($fields + ['CHECKSUMHASH' => $sum], self::STORE_KEY, $sum));
        $this->assertFalse(PaytmChecksum::verify(['TXNAMOUNT' => '1.00'] + $fields, self::STORE_KEY, $sum));
        $this->assertFalse(PaytmChecksum::verify($fields, self::PLATFORM_KEY, $sum));
        $this->assertFalse(PaytmChecksum::verify($fields, self::STORE_KEY, 'garbage'));
    }

    public function test_initiate_order_signs_the_request_and_posts_the_token_to_paytm(): void
    {
        [, , $tx] = $this->orderTx(100.0);
        Http::fake(['securegw-stage.paytm.in/theia/api/v1/initiateTransaction*' => Http::response(['body' => [
            'resultInfo' => ['resultStatus' => 'S', 'resultMsg' => 'Success'], 'txnToken' => 'tok_fake_1',
        ]])]);

        $response = TestResponse::fromBaseResponse(PaytmController::pay($tx));

        $response->assertOk()->assertViewIs('owner.billing.gateways.redirect-form')
            ->assertViewHas('url', 'https://securegw-stage.paytm.in/theia/api/v1/showPaymentPage?mid=STOREMID0001&orderId='.$tx->id)
            ->assertViewHas('data', ['mid' => 'STOREMID0001', 'orderId' => (string) $tx->id, 'txnToken' => 'tok_fake_1']);
        Http::assertSent(function (ClientRequest $r) use ($tx) {
            $raw = $r->body();
            $body = substr($raw, 8, strpos($raw, ',"head":') - 8);
            $sent = json_decode($body, true);

            return $sent['mid'] === 'STOREMID0001' && $sent['orderId'] === (string) $tx->id
                && $sent['txnAmount'] === ['value' => '100.00', 'currency' => 'INR']
                && $sent['callbackUrl'] === route('ipn', 'paytm')
                && PaytmChecksum::verify($body, self::STORE_KEY, $r->data()['head']['signature']);
        });
    }

    public function test_initiate_refused_or_unconfigured_goes_back_with_the_reason(): void
    {
        [, $tx] = $this->makePlanTransaction(10.0, 'INR');
        Http::fake(['securegw.paytm.in/*' => Http::response(['body' => ['resultInfo' => ['resultStatus' => 'F', 'resultMsg' => 'Invalid mid']]])]);

        $this->assertTrue(PaytmController::pay($tx)->isRedirect());
        $this->assertSame('Invalid mid', session('app_alert_message'));

        config(['settings.PAYTM_MERCHANT_KEY' => '']);
        $this->assertTrue(PaytmController::pay($tx)->isRedirect());
        $this->assertSame('error', session('app_alert_type'));
    }

    public function test_return_settles_once_only_with_a_valid_checksum_and_confirmed_status(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $order, $tx] = $this->orderTx(100.0);
        $this->fakeStatus();

        // Tampered amount → checksum fails.
        $forged = $this->paytmPost($tx, self::STORE_KEY);
        $forged['TXNAMOUNT'] = '1.00';
        $this->post(route('ipn', 'paytm'), $forged)->assertRedirect();
        // Signed with another merchant's key.
        $this->post(route('ipn', 'paytm'), $this->paytmPost($tx, self::PLATFORM_KEY))->assertRedirect();
        // Declined by the bank.
        $this->post(route('ipn', 'paytm'), $this->paytmPost($tx, self::STORE_KEY, 'TXN_FAILURE'))->assertRedirect();
        $this->assertStringContainsString('Bank declined', (string) session('app_alert_message'));
        $this->assertNotSettled($order, $tx);

        $this->post(route('ipn', 'paytm'), $this->paytmPost($tx, self::STORE_KEY))->assertOk();
        $this->assertSettledOnce($order, $tx);
        $this->assertSame('TXN'.$tx->id, Transaction::find($tx->id)->payment_id);
        Http::assertSent(fn (ClientRequest $r) => str_starts_with($r->url(), 'https://securegw-stage.paytm.in/v3/order/status'));

        $this->post(route('ipn', 'paytm'), $this->paytmPost($tx, self::STORE_KEY))->assertRedirect(route('subscription'));
        $this->assertSettledOnce($order, $tx);
    }

    public function test_return_does_not_settle_when_paytm_reports_a_different_amount(): void
    {
        [, $order, $tx] = $this->orderTx(100.0);
        $this->fakeStatus('TXN_SUCCESS', '1.00');

        $this->post(route('ipn', 'paytm'), $this->paytmPost($tx, self::STORE_KEY))->assertRedirect();
        $this->assertNotSettled($order, $tx);
    }

    public function test_return_without_fields_is_an_invalid_transaction_not_a_500(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $this->get(route('ipn', 'paytm'))->assertRedirect(route('subscription'));
        $this->post(route('ipn', 'paytm'), [])->assertRedirect(route('subscription'));
    }

    public function test_webhook_signature_valid_invalid_and_replay(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $tx] = $this->makePlanTransaction(100.0, 'INR', 'wallet_topup');
        $this->fakeStatus();

        $this->post(route('webhook', 'paytm'), $this->paytmPost($tx, self::STORE_KEY))->assertStatus(400);
        $this->assertNull($tx->fresh()->status);
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'paytm: webhook rejected (invalid signature)']);

        $this->post(route('webhook', 'paytm'), $this->paytmPost($tx, self::PLATFORM_KEY))->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSame(Transaction::STATUS_SUCCESS, $tx->fresh()->status);
        $credited = $tx->fresh()->credited_at;
        $this->assertNotNull($credited);

        $this->post(route('webhook', 'paytm'), $this->paytmPost($tx, self::PLATFORM_KEY))->assertOk();
        $this->assertEquals($credited, $tx->fresh()->credited_at);
    }

    public function test_refund(): void
    {
        [, $tx] = $this->makePlanTransaction(100.0, 'INR', 'wallet_topup');
        $tx->update(['status' => Transaction::STATUS_SUCCESS, 'payment_id' => 'TXN42']);
        Http::fake(['securegw.paytm.in/refund/apply' => Http::response(['body' => [
            'resultInfo' => ['resultStatus' => 'PENDING'], 'refundId' => 'RF1',
        ]])]);

        $this->assertSame(['ok' => true, 'reference' => 'RF1', 'message' => null], PaytmController::adapter()->refund($tx->fresh(), 40.0));
        Http::assertSent(fn (ClientRequest $r) => $r->data()['body']['txnId'] === 'TXN42' && $r->data()['body']['refundAmount'] === '40.00'
            && $r->data()['body']['mid'] === 'PLATFORMMID01');
    }
}
