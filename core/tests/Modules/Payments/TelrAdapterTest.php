<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TelrController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06b — Telr adapter over a faked HTTP client (no network, fake keys).
 */
class TelrAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'telr', 'payment_title' => 'Telr', 'payment_install' => '1']);
        config([
            'settings.telr_store_id' => '10001',
            'settings.telr_authkey' => 'fake-platform-authkey',
            'settings.telr_sandbox_mode' => 'live',
            'settings.currency_code' => 'AED',
        ]);
    }

    private function orderTx(float $amount = 12.345, string $currency = 'BHD'): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('telr', $amount, ['store_id' => '20002', 'authkey' => 'fake-store-authkey'], $currency);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return [$store, $order, $tx];
    }

    /** @return array<string, mixed> Telr's answer to `check` */
    private function check(Transaction $tx, int $code = 3, string $amount = '12.345', string $currency = 'BHD'): array
    {
        return ['method' => 'check', 'order' => ['ref' => 'REF'.$tx->id, 'cartid' => (string) $tx->id, 'amount' => $amount, 'currency' => $currency,
            'status' => ['code' => $code, 'text' => $code === 3 ? 'Paid' : 'Pending']]];
    }

    public function test_initiate_order_uses_the_store_keys_and_test_mode(): void
    {
        [, , $tx] = $this->orderTx();
        Http::fake([TelrAdapterTest::endpoint() => Http::response(['method' => 'create', 'order' => ['ref' => 'REF'.$tx->id, 'url' => 'https://secure.telr.com/gateway/process.html?o=X']])]);

        $response = TelrController::pay($tx);

        $this->assertSame('https://secure.telr.com/gateway/process.html?o=X', $response->headers->get('Location'));
        $this->assertSame('REF'.$tx->id, $tx->fresh()->payment_id);
        $back = route('ipn', 'telr').'?order_id='.$tx->id;
        Http::assertSent(fn (ClientRequest $r) => $r['store'] === '20002' && $r['authkey'] === 'fake-store-authkey' && $r['method'] === 'create'
            && $r['order']['test'] === 1 && $r['order']['amount'] === '12.345' && $r['order']['currency'] === 'BHD'
            && $r['order']['cartid'] === (string) $tx->id && $r['return']['authorised'] === $back
            && $r['return']['cancelled'] === $back.'&outcome=cancelled');
    }

    public function test_initiate_refused_or_non_json_goes_back(): void
    {
        [, $tx] = $this->makePlanTransaction(10.0, 'AED');
        Http::fake([TelrAdapterTest::endpoint() => Http::sequence()
            ->push(['method' => 'create', 'error' => ['message' => 'Invalid authentication key', 'note' => 'x']])
            ->push('<html>bad gateway</html>', 502)]);

        $this->assertTrue(TelrController::pay($tx)->isRedirect());
        $this->assertSame('Invalid authentication key', session('app_alert_message'));
        $this->assertTrue(TelrController::pay($tx)->isRedirect());
        $this->assertSame('error', session('app_alert_type'));
        $this->assertNull($tx->fresh()->payment_id);
    }

    public function test_return_settles_only_a_paid_full_order_once(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [$store, $order, $tx] = $this->orderTx();
        $tx->update(['payment_id' => 'REF'.$tx->id]);
        Http::fake([TelrAdapterTest::endpoint() => Http::sequence()
            ->push($this->check($tx, 1))
            ->push($this->check($tx, 3, '1.000'))
            ->push($this->check($tx, 3, '12.345', 'AED'))
            ->push($this->check($tx))]);
        $url = route('ipn', 'telr').'?order_id='.$tx->id;

        // Pending / short / wrong currency: back to the store page, not settled.
        $this->get($url)->assertRedirect(route('publicView', $store->slug));
        $this->get($url)->assertRedirect(route('publicView', $store->slug));
        $this->get($url.'&outcome=declined')->assertRedirect(route('publicView', $store->slug));
        $this->assertNotSettled($order, $tx);

        $this->get($url)->assertOk();
        $this->assertSettledOnce($order, $tx);
        Http::assertSent(fn (ClientRequest $r) => $r['method'] === 'check' && $r['order']['ref'] === 'REF'.$tx->id);

        $this->get($url)->assertRedirect(route('subscription'));
        $this->assertSettledOnce($order, $tx);
    }

    public function test_advice_webhook_settles_only_through_the_authenticated_check(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $tx] = $this->makePlanTransaction(10.0, 'AED', 'wallet_topup');
        $tx->update(['payment_id' => 'REF'.$tx->id]);
        Http::fake([TelrAdapterTest::endpoint() => Http::sequence()
            ->push($this->check($tx, 1, '10.00', 'AED'))
            ->push($this->check($tx, 3, '10.00', 'AED'))]);

        // A forged "paid" advice is only a hint: Telr says pending, nothing moves.
        $this->post(route('webhook', 'telr'), ['tran_cartid' => (string) $tx->id, 'tran_status' => 'A'])->assertOk();
        $this->assertNull($tx->fresh()->status);

        $this->post(route('webhook', 'telr'), ['tran_cartid' => (string) $tx->id, 'tran_status' => 'A'])->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSame(Transaction::STATUS_SUCCESS, $tx->fresh()->status);
        $this->assertNotNull($tx->fresh()->credited_at);

        // Replay / unknown cart: acknowledged, no call.
        $this->post(route('webhook', 'telr'), ['tran_cartid' => (string) $tx->id])->assertOk();
        $this->post(route('webhook', 'telr'), ['tran_cartid' => '99999999'])->assertOk();
        Http::assertSentCount(2);
    }

    public function test_refund_is_not_offered(): void
    {
        $this->assertFalse(TelrController::adapter()->supportsRefund());
    }

    public static function endpoint(): string
    {
        return 'secure.telr.com/gateway/order.json';
    }
}
