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
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\IyzicoController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06b — iyzico adapter over a faked HTTP client (no network, fake keys).
 */
class IyzicoAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const STORE_SECRET = 'sandbox-fake-store-secret';

    private const PLATFORM_SECRET = 'sandbox-fake-platform-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'iyzico', 'payment_title' => 'Iyzico', 'payment_install' => '1']);
        config([
            'settings.iyzico_sandbox_mode' => 'live',
            'settings.iyzico_api_key' => 'fake-platform-api',
            'settings.iyzico_secret_key' => self::PLATFORM_SECRET,
            'settings.currency_code' => 'TRY',
        ]);
    }

    private function orderTx(float $amount = 100.0): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('iyzico', $amount, ['api_key' => 'fake-store-api', 'secret_key' => self::STORE_SECRET], 'TRY');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'TRY');
        $tx->update(['details' => ['customer_name' => 'Ayse Yilmaz Demir']]);

        return [$store, $order, $tx];
    }

    /** @return array<string, mixed> a retrieved checkout form, signed with $secret */
    private function form(Transaction $tx, string $secret, array $overrides = []): array
    {
        $form = array_merge([
            'status' => 'success', 'paymentStatus' => 'SUCCESS', 'fraudStatus' => 1, 'paymentId' => 'PAY'.$tx->id,
            'currency' => 'TRY', 'basketId' => (string) $tx->id, 'conversationId' => (string) $tx->id,
            'paidPrice' => 100.0, 'price' => 100.0, 'token' => 'tok-'.$tx->id,
        ], $overrides);
        $form['signature'] = hash_hmac('sha256', implode(':', [
            $form['paymentStatus'], $form['paymentId'], $form['currency'], $form['basketId'], $form['conversationId'],
            rtrim(rtrim(number_format($form['paidPrice'], 8, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($form['price'], 8, '.', ''), '0'), '.'), $form['token'],
        ]), $secret);

        return $form;
    }

    public function test_initiate_order_signs_with_the_store_key_and_redirects_to_the_form(): void
    {
        [$store, , $tx] = $this->orderTx(100.0);
        Http::fake(['sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/initialize/auth/ecom' => Http::response([
            'status' => 'success', 'token' => 'tok-1', 'paymentPageUrl' => 'https://sandbox-cpp.iyzipay.com?token=tok-1',
        ])]);

        $response = IyzicoController::pay($tx);

        $this->assertSame('https://sandbox-cpp.iyzipay.com?token=tok-1', $response->headers->get('Location'));
        Http::assertSent(function (ClientRequest $r) use ($tx) {
            $random = $r->header('x-iyzi-rnd')[0];
            $auth = base64_decode(substr($r->header('Authorization')[0], strlen('IYZWSv2 ')));
            $expected = 'apiKey:fake-store-api&randomKey:'.$random.'&signature:'
                .hash_hmac('sha256', $random.'/payment/iyzipos/checkoutform/initialize/auth/ecom'.$r->body(), self::STORE_SECRET);

            return $auth === $expected && $r['price'] === '100.00' && $r['currency'] === 'TRY' && $r['basketId'] === (string) $tx->id
                && $r['buyer']['name'] === 'Ayse' && $r['buyer']['surname'] === 'Yilmaz Demir'
                && strlen($r['buyer']['identityNumber']) === 11
                && $r['callbackUrl'] === route('ipn', 'iyzico').'?order_id='.$tx->id;
        });
    }

    public function test_initiate_refusal_goes_back_with_the_reason(): void
    {
        [, $tx] = $this->makePlanTransaction(10.0, 'TRY');
        Http::fake(['api.iyzipay.com/*' => Http::response(['status' => 'failure', 'errorMessage' => 'Geçersiz imza'])]);

        $this->assertTrue(IyzicoController::pay($tx)->isRedirect());
        $this->assertSame('Geçersiz imza', session('app_alert_message'));
    }

    public function test_return_settles_only_a_signed_successful_full_payment_once(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $order, $tx] = $this->orderTx(100.0);
        $url = route('ipn', 'iyzico').'?order_id='.$tx->id;
        Http::fake(['sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail' => Http::sequence()
            ->push($this->form($tx, self::STORE_SECRET, ['paymentStatus' => 'FAILURE']))   // API call ok, payment failed (old code settled this)
            ->push($this->form($tx, self::STORE_SECRET, ['price' => 1.0, 'paidPrice' => 1.0])) // short
            ->push($this->form($tx, self::STORE_SECRET, ['fraudStatus' => 0]))            // held for review
            ->push($this->form($tx, self::PLATFORM_SECRET))                                // bad signature
            ->push($this->form($tx, self::STORE_SECRET))
            ->push($this->form($tx, self::STORE_SECRET))]);

        for ($i = 0; $i < 4; $i++) {
            $this->post($url, ['token' => 'tok-'.$tx->id])->assertRedirect();
            $this->assertNotSettled($order, $tx);
        }

        $this->post($url, ['token' => 'tok-'.$tx->id])->assertOk();
        $this->assertSettledOnce($order, $tx);
        $this->assertSame('PAY'.$tx->id, Transaction::find($tx->id)->payment_id);

        $this->post($url, ['token' => 'tok-'.$tx->id])->assertRedirect(route('subscription'));
        $this->assertSettledOnce($order, $tx);
    }

    /**
     * WI-15-sec: a store owner with their own iyzico keys pays another pending
     * transaction (here a platform wallet top-up) into their own merchant
     * account, then posts the return under their own store order's id. The
     * form reads back fine with their keys; it must still not settle.
     */
    public function test_return_does_not_settle_a_transaction_other_than_its_order_id(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $order, $attackerTx] = $this->orderTx(100.0);
        [, $victimTx] = $this->makePlanTransaction(100.0, 'TRY', 'wallet_topup');
        Http::fake(['sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail' => Http::response(
            $this->form($victimTx, self::STORE_SECRET, ['paymentId' => 'PAY-ATTACK'])
        )]);

        $this->post(route('ipn', 'iyzico').'?order_id='.$attackerTx->id, ['token' => 'tok-'.$victimTx->id])
            ->assertRedirect(route('subscription'));

        $this->assertNull($victimTx->fresh()->status);
        $this->assertNull($victimTx->fresh()->credited_at);
        $this->assertNotSettled($order, $attackerTx);
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'iyzico: webhook rejected (return names another transaction)']);
    }

    /** WI-15-sec: without an order_id the platform keys read the form, so only a platform-keyed transaction may settle. */
    public function test_return_without_order_id_does_not_settle_a_store_order(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $order, $tx] = $this->orderTx(100.0);
        Http::fake(['api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail' => Http::response(
            $this->form($tx, self::PLATFORM_SECRET)
        )]);

        $this->post(route('ipn', 'iyzico'), ['token' => 'tok-'.$tx->id])->assertRedirect(route('subscription'));

        $this->assertNotSettled($order, $tx);
    }

    public function test_return_without_token_or_with_unknown_order_settles_nothing(): void
    {
        $this->post(route('ipn', 'iyzico'), [])->assertRedirect();
        $this->post(route('ipn', 'iyzico').'?order_id=99999999', ['token' => 'x'])->assertRedirect();
        $this->assertSame('error', session('app_alert_type'));
        Http::assertNothingSent();
    }

    private function webhook(array $event, ?string $secret): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_X_IYZ_SIGNATURE_V3'] = hash_hmac('sha256', $secret.$event['iyziEventType'].$event['iyziPaymentId'].$event['token'].$event['paymentConversationId'].$event['status'], $secret);
        }

        return $this->call('POST', route('webhook', 'iyzico'), [], [], [], $server, json_encode($event));
    }

    public function test_webhook_signature_valid_invalid_and_replay(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $tx] = $this->makePlanTransaction(100.0, 'TRY', 'wallet_topup');
        Http::fake(['api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail' => Http::response($this->form($tx, self::PLATFORM_SECRET))]);
        $event = ['paymentConversationId' => (string) $tx->id, 'merchantId' => '1', 'token' => 'tok-'.$tx->id, 'status' => 'SUCCESS',
            'iyziReferenceCode' => 'r', 'iyziEventType' => 'CHECKOUT_FORM_AUTH', 'iyziEventTime' => 1, 'iyziPaymentId' => 'PAY'.$tx->id];

        $this->webhook($event, null)->assertStatus(400);
        $this->webhook($event, self::STORE_SECRET)->assertStatus(400);
        $this->assertNull($tx->fresh()->status);
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'iyzico: webhook rejected (invalid signature)']);

        $this->webhook($event, self::PLATFORM_SECRET)->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSame(Transaction::STATUS_SUCCESS, $tx->fresh()->status);
        $credited = $tx->fresh()->credited_at;
        $this->assertNotNull($credited);

        $this->webhook($event, self::PLATFORM_SECRET)->assertOk();
        $this->assertEquals($credited, $tx->fresh()->credited_at);
        Http::assertSentCount(1);
    }

    public function test_refund(): void
    {
        [, $tx] = $this->makePlanTransaction(100.0, 'TRY', 'wallet_topup');
        $tx->update(['status' => Transaction::STATUS_SUCCESS, 'payment_id' => 'PAY9']);
        Http::fake(['api.iyzipay.com/v2/payment/refund' => Http::response(['status' => 'success', 'paymentId' => 'PAY9', 'price' => 40.0])]);

        $this->assertSame(['ok' => true, 'reference' => 'PAY9', 'message' => null], IyzicoController::adapter()->refund($tx->fresh(), 40.0));
        Http::assertSent(fn (ClientRequest $r) => $r['paymentId'] === 'PAY9' && $r['price'] === '40.00');
    }
}
