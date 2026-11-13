<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\CcavenueController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\CcavenueAdapter;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06b — CCAvenue adapter (no network, fake keys): the encrypted hand-off
 * and the encrypted response.
 */
class CcavenueAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const STORE_KEY = 'FAKESTOREWORKINGKEY00000000000AB';

    private const PLATFORM_KEY = 'FAKEPLATFORMWORKINGKEY000000000C';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'ccavenue', 'payment_title' => 'CCAvenue', 'payment_install' => '1']);
        config([
            'settings.CCAVENUE_MERCHANT_KEY' => '111111',
            'settings.CCAVENUE_ACCESS_CODE' => 'AVFAKEPLATFORM',
            'settings.CCAVENUE_WORKING_KEY' => self::PLATFORM_KEY,
            'settings.currency_code' => 'INR',
        ]);
    }

    private function orderTx(float $amount = 499.5): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('ccavenue', $amount, [
            'merchant_key' => '222222', 'access_code' => 'AVFAKESTORE', 'working_key' => self::STORE_KEY,
        ], 'INR');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'INR');

        return [$store, $order, $tx];
    }

    private function encResp(Transaction $tx, string $key, string $status = 'Success', string $amount = '499.50', string $currency = 'INR'): string
    {
        return CcavenueAdapter::encrypt(http_build_query([
            'order_id' => (string) $tx->id, 'tracking_id' => '3100'.$tx->id, 'bank_ref_no' => 'B1', 'order_status' => $status,
            'failure_message' => '', 'payment_mode' => 'Net Banking', 'status_message' => 'Y&Z=ok', 'currency' => $currency, 'amount' => $amount,
        ]), $key);
    }

    public function test_initiate_order_encrypts_the_request_with_the_store_key(): void
    {
        [, , $tx] = $this->orderTx();

        $response = TestResponse::fromBaseResponse(CcavenueController::pay($tx));

        $response->assertOk()->assertViewIs('owner.billing.gateways.redirect-form')->assertViewHas('url', CcavenueAdapter::ENDPOINT);
        $data = $response->viewData('data');
        $this->assertSame('AVFAKESTORE', $data['access_code']);
        parse_str((string) CcavenueAdapter::decrypt($data['encRequest'], self::STORE_KEY), $sent);
        $back = route('ipn', 'ccavenue').'?order_id='.$tx->id;
        $this->assertSame(['222222', (string) $tx->id, '499.50', 'INR', $back, $back],
            [$sent['merchant_id'], $sent['order_id'], $sent['amount'], $sent['currency'], $sent['redirect_url'], $sent['cancel_url']]);
    }

    public function test_initiate_unconfigured_goes_back(): void
    {
        [, $tx] = $this->makePlanTransaction(10.0, 'INR');
        config(['settings.CCAVENUE_WORKING_KEY' => '']);

        $this->assertTrue(CcavenueController::pay($tx)->isRedirect());
        $this->assertSame('error', session('app_alert_type'));
    }

    public function test_return_settles_only_a_genuine_full_success_once(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [$store, $order, $tx] = $this->orderTx();
        $url = route('ipn', 'ccavenue').'?order_id='.$tx->id;

        $this->post($url, [])->assertRedirect(route('publicView', $store->slug));                                          // no encResp (was a 500)
        $this->post($url, ['encResp' => $this->encResp($tx, self::PLATFORM_KEY)])->assertRedirect();                      // wrong key
        $this->post($url, ['encResp' => $this->encResp($tx, self::STORE_KEY, 'Failure')])->assertRedirect();
        $this->post($url, ['encResp' => $this->encResp($tx, self::STORE_KEY, 'Success', '4.99')])->assertRedirect();       // short
        $this->post($url, ['encResp' => $this->encResp($tx, self::STORE_KEY, 'Aborted')])->assertRedirect(route('publicView', $store->slug));
        $this->assertNotSettled($order, $tx);

        $this->post($url, ['encResp' => $this->encResp($tx, self::STORE_KEY)])->assertOk();
        $this->assertSettledOnce($order, $tx);
        $this->assertSame('3100'.$tx->id, Transaction::find($tx->id)->payment_id);

        $this->post($url, ['encResp' => $this->encResp($tx, self::STORE_KEY)])->assertRedirect(route('subscription'));
        $this->assertSettledOnce($order, $tx);
    }

    public function test_a_payload_for_another_order_does_not_settle_this_one(): void
    {
        [, $order, $tx] = $this->orderTx();
        [, , $other] = $this->orderTx();

        $this->post(route('ipn', 'ccavenue').'?order_id='.$tx->id, ['encResp' => $this->encResp($other, self::STORE_KEY)])->assertRedirect();
        $this->assertNotSettled($order, $tx);
    }

    public function test_legacy_plan_return_without_order_id_and_the_webhook(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $tx] = $this->makePlanTransaction(499.5, 'INR', 'wallet_topup');
        $this->post(route('ipn', 'ccavenue'), ['encResp' => $this->encResp($tx, self::PLATFORM_KEY)])->assertRedirect();
        $this->assertSame(Transaction::STATUS_SUCCESS, $tx->fresh()->status);

        [, $tx2] = $this->makePlanTransaction(499.5, 'INR', 'wallet_topup');
        $hook = route('webhook', 'ccavenue').'?order_id='.$tx2->id;
        $this->post($hook, ['encResp' => $this->encResp($tx2, self::STORE_KEY)])->assertStatus(400);
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'ccavenue: webhook rejected (invalid signature)']);
        $this->post($hook, ['encResp' => $this->encResp($tx2, self::PLATFORM_KEY)])->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSame(Transaction::STATUS_SUCCESS, $tx2->fresh()->status);
        $this->assertNotNull($tx2->fresh()->credited_at);
        $this->post($hook, ['encResp' => $this->encResp($tx2, self::PLATFORM_KEY)])->assertOk();
        $this->post(route('webhook', 'ccavenue'), [])->assertOk();
    }
}
