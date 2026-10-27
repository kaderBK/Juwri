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
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaytabsController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06b — PayTabs adapter over a faked HTTP client (no network, fake keys).
 */
class PaytabsAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const STORE_KEY = 'SFAKE-STORE-SERVERKEY';

    private const PLATFORM_KEY = 'SFAKE-PLATFORM-SERVERKEY';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'paytabs', 'payment_title' => 'PayTabs', 'payment_install' => '1']);
        config([
            'settings.paytabs_profile_id' => '1001',
            'settings.paytabs_secret_key' => self::PLATFORM_KEY,
            'settings.paytabs_region' => 'ARE',
            'settings.currency_code' => 'AED',
        ]);
    }

    private function orderTx(float $amount = 1.234, string $currency = 'OMR'): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('paytabs', $amount, ['profile_id' => '2002', 'secret_key' => self::STORE_KEY, 'region' => 'omn'], $currency);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return [$store, $order, $tx];
    }

    /** @return array<string, string> the buyer's return post, signed with $key */
    private function returnPost(Transaction $tx, string $key, string $status = 'A'): array
    {
        $fields = ['acquirerMessage' => '', 'acquirerRRN' => '', 'cartId' => (string) $tx->id, 'customerEmail' => 'd@example.test',
            'respCode' => $status === 'A' ? 'G1' : 'D1', 'respMessage' => $status === 'A' ? 'Authorised' : 'Declined', 'respStatus' => $status,
            'token' => '', 'tranRef' => 'TST'.$tx->id];
        $signed = array_filter($fields, fn ($v) => $v !== '');
        ksort($signed);
        $fields['signature'] = hash_hmac('sha256', http_build_query($signed), $key);

        return $fields;
    }

    private function fakeQuery(string $amount = '1.234', string $currency = 'OMR', string $status = 'A'): void
    {
        Http::fake(['*/payment/query' => fn (ClientRequest $r) => Http::response([
            'tran_ref' => $r['tran_ref'], 'cart_id' => substr($r['tran_ref'], 3), 'cart_amount' => $amount, 'cart_currency' => $currency,
            'payment_result' => ['response_status' => $status, 'response_message' => $status === 'A' ? 'Authorised' : 'Declined'],
        ])]);
    }

    public function test_initiate_order_uses_the_store_region_and_three_decimal_amount(): void
    {
        [, , $tx] = $this->orderTx();
        Http::fake(['secure-oman.paytabs.com/payment/request' => Http::response(['tran_ref' => 'TST'.$tx->id, 'redirect_url' => 'https://secure-oman.paytabs.com/payment/page/X'])]);

        $response = PaytabsController::pay($tx);

        $this->assertSame('https://secure-oman.paytabs.com/payment/page/X', $response->headers->get('Location'));
        $this->assertSame('TST'.$tx->id, $tx->fresh()->payment_id);
        Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('authorization', self::STORE_KEY) && $r['profile_id'] === 2002
            && $r['cart_amount'] === '1.234' && $r['cart_currency'] === 'OMR' && $r['cart_id'] === (string) $tx->id
            && $r['callback'] === url('webhook/paytabs') && $r['return'] === route('ipn', 'paytabs'));
    }

    public function test_initiate_with_an_unknown_region_or_a_refusal_goes_back(): void
    {
        [$store, , $tx] = $this->orderTx();
        StoreOption::updateStoreOption($store->id, 'restaurant_paytabs_region', 'Mars');
        $this->assertTrue(PaytabsController::pay($tx)->isRedirect());
        $this->assertSame('error', session('app_alert_type'));

        [, $plan] = $this->makePlanTransaction(10.0, 'AED');
        Http::fake(['secure.paytabs.com/payment/request' => Http::response(['code' => 1, 'message' => 'Authentication failed'], 400)]);
        $this->assertTrue(PaytabsController::pay($plan)->isRedirect());
        $this->assertSame('Authentication failed', session('app_alert_message'));
    }

    public function test_return_verifies_the_signature_and_the_query_then_settles_once(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $order, $tx] = $this->orderTx();
        $tx->update(['payment_id' => 'TST'.$tx->id]);
        $this->fakeQuery();

        $forged = $this->returnPost($tx, self::STORE_KEY);
        $forged['respStatus'] = 'A';
        $forged['respMessage'] = 'Edited';
        $this->post(route('ipn', 'paytabs'), $forged)->assertRedirect();
        $this->post(route('ipn', 'paytabs'), $this->returnPost($tx, self::PLATFORM_KEY))->assertRedirect();
        $this->post(route('ipn', 'paytabs'), $this->returnPost($tx, self::STORE_KEY, 'D'))->assertRedirect();
        $this->assertStringContainsString('Declined', (string) session('app_alert_message'));
        $this->assertNotSettled($order, $tx);

        $this->post(route('ipn', 'paytabs'), $this->returnPost($tx, self::STORE_KEY))->assertOk();
        $this->assertSettledOnce($order, $tx);
        Http::assertSent(fn (ClientRequest $r) => str_starts_with($r->url(), 'https://secure-oman.paytabs.com/payment/query'));

        $this->post(route('ipn', 'paytabs'), $this->returnPost($tx, self::STORE_KEY))->assertRedirect(route('subscription'));
        $this->assertSettledOnce($order, $tx);
    }

    public function test_return_does_not_settle_when_the_query_disagrees_on_amount_or_currency(): void
    {
        [, $order, $tx] = $this->orderTx();
        $tx->update(['payment_id' => 'TST'.$tx->id]);
        Http::fake(['*/payment/query' => Http::sequence()
            ->push(['cart_id' => (string) $tx->id, 'cart_amount' => '0.100', 'cart_currency' => 'OMR', 'payment_result' => ['response_status' => 'A']])
            ->push(['cart_id' => (string) $tx->id, 'cart_amount' => '1.234', 'cart_currency' => 'USD', 'payment_result' => ['response_status' => 'A']])]);

        $this->post(route('ipn', 'paytabs'), $this->returnPost($tx, self::STORE_KEY))->assertRedirect();
        $this->post(route('ipn', 'paytabs'), $this->returnPost($tx, self::STORE_KEY))->assertRedirect();
        $this->assertNotSettled($order, $tx);
    }

    public function test_return_with_a_form_post_or_empty_body_is_not_a_500(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $this->post(route('ipn', 'paytabs'), [])->assertRedirect(route('subscription'));
        $this->get(route('ipn', 'paytabs'))->assertRedirect(route('subscription'));
    }

    private function serverCallback(string $payload, ?string $key, string $route = 'webhook'): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($key !== null) {
            $server['HTTP_SIGNATURE'] = hash_hmac('sha256', $payload, $key);
        }

        return $this->call('POST', route($route, 'paytabs'), [], [], [], $server, $payload);
    }

    public function test_webhook_signature_valid_invalid_and_replay(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $tx] = $this->makePlanTransaction(49.5, 'AED', 'wallet_topup');
        $tx->update(['payment_id' => 'TST'.$tx->id]);
        $this->fakeQuery('49.50', 'AED');
        $payload = json_encode(['tran_ref' => 'TST'.$tx->id, 'cart_id' => (string) $tx->id, 'cart_amount' => '49.50', 'cart_currency' => 'AED',
            'payment_result' => ['response_status' => 'A']]);

        $this->serverCallback($payload, null)->assertStatus(400);
        $this->serverCallback($payload, self::STORE_KEY)->assertStatus(400);
        $this->assertNull($tx->fresh()->status);
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'paytabs: webhook rejected (invalid signature)']);

        // A payment started before the callback moved still posts its JSON to ipn/paytabs.
        $this->serverCallback($payload, self::PLATFORM_KEY, 'ipn')->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSame(Transaction::STATUS_SUCCESS, $tx->fresh()->status);
        $credited = $tx->fresh()->credited_at;
        $this->assertNotNull($credited);

        $this->serverCallback($payload, self::PLATFORM_KEY)->assertOk();
        $this->assertEquals($credited, $tx->fresh()->credited_at);
    }

    public function test_refund(): void
    {
        [, $tx] = $this->makePlanTransaction(49.5, 'AED', 'wallet_topup');
        $tx->update(['status' => Transaction::STATUS_SUCCESS, 'payment_id' => 'TST7']);
        Http::fake(['secure.paytabs.com/payment/request' => Http::response(['tran_ref' => 'TST8', 'payment_result' => ['response_status' => 'A']])]);

        $this->assertSame(['ok' => true, 'reference' => 'TST8', 'message' => null], PaytabsController::adapter()->refund($tx->fresh(), 20.0));
        Http::assertSent(fn (ClientRequest $r) => $r['tran_type'] === 'refund' && $r['tran_ref'] === 'TST7' && $r['cart_amount'] === '20.00');
    }
}
