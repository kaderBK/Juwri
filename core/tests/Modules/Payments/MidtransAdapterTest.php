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
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\MidtransController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06b — Midtrans Snap adapter over a faked HTTP client (no network, fake keys).
 */
class MidtransAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const STORE_KEY = 'SB-Mid-server-fakeStore';

    private const PLATFORM_KEY = 'Mid-server-fakePlatform';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'midtrans', 'payment_title' => 'Midtrans', 'payment_install' => '1']);
        config([
            'settings.midtrans_server_key' => self::PLATFORM_KEY,
            'settings.midtrans_client_key' => 'Mid-client-fakePlatform',
            'settings.midtrans_sandbox_mode' => 'live',
            'settings.currency_code' => 'IDR',
        ]);
    }

    private function orderTx(float $amount = 150000.0): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('midtrans', $amount, ['server_key' => self::STORE_KEY, 'client_key' => 'SB-Mid-client-fakeStore'], 'IDR');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'IDR');

        return [$store, $order, $tx];
    }

    private function fakeStatus(array ...$states): void
    {
        $seq = Http::sequence();
        foreach ($states as $state) {
            $seq->push($state);
        }
        Http::fake(['*/v2/*/status' => $seq]);
    }

    /** @return array<string, string> */
    private function mtStatus(Transaction $tx, string $state = 'settlement', string $amount = '150000.00', ?string $fraud = null): array
    {
        return array_filter(['order_id' => (string) $tx->id, 'transaction_id' => 'mt-'.$tx->id, 'transaction_status' => $state,
            'gross_amount' => $amount, 'currency' => 'IDR', 'status_code' => '200', 'fraud_status' => $fraud]);
    }

    public function test_initiate_order_uses_the_store_keys_and_renders_snap(): void
    {
        [, , $tx] = $this->orderTx();
        Http::fake(['app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap-tok', 'redirect_url' => 'https://x'], 201)]);

        TestResponse::fromBaseResponse(MidtransController::pay($tx))->assertOk()->assertViewIs('owner.billing.gateways.midtrans')
            ->assertViewHas('snapToken', 'snap-tok')->assertViewHas('mt_client_key', 'SB-Mid-client-fakeStore')
            ->assertViewHas('url', 'https://app.sandbox.midtrans.com/snap/snap.js')
            ->assertViewHas('return_url', route('ipn', 'midtrans').'?order_id='.$tx->id);
        Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode(self::STORE_KEY.':'))
            && $r->hasHeader('X-Override-Notification', url('webhook/midtrans').'?order_id='.$tx->id)
            && $r['transaction_details'] === ['order_id' => (string) $tx->id, 'gross_amount' => 150000]
            && $r['item_details'][0]['price'] === 150000);
    }

    public function test_initiate_refusal_goes_back_with_the_reason(): void
    {
        [, $tx] = $this->makePlanTransaction(10000.0, 'IDR');
        Http::fake(['app.midtrans.com/*' => Http::response(['error_messages' => ['Access denied due to unauthorized transaction']], 401)]);

        $this->assertTrue(MidtransController::pay($tx)->isRedirect());
        $this->assertSame('Access denied due to unauthorized transaction', session('app_alert_message'));
    }

    private function notify(array $event, ?string $key): TestResponse
    {
        if ($key !== null) {
            $event['signature_key'] = hash('sha512', $event['order_id'].$event['status_code'].$event['gross_amount'].$key);
        }

        return $this->call('POST', route('webhook', 'midtrans'), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event));
    }

    public function test_webhook_signature_pending_amount_and_replay(): void
    {
        [, $order, $tx] = $this->orderTx();
        $this->fakeStatus(
            $this->mtStatus($tx, 'pending'),              // bank transfer not paid yet (the old driver settled here)
            $this->mtStatus($tx, 'settlement', '1000.00'), // short
            $this->mtStatus($tx, 'capture', '150000.00', 'challenge'),
            $this->mtStatus($tx),
        );
        $event = $this->mtStatus($tx);

        $this->notify($event, null)->assertStatus(400);
        $this->notify($event, self::PLATFORM_KEY)->assertStatus(400);
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'midtrans: webhook rejected (invalid signature)']);

        for ($i = 0; $i < 3; $i++) {
            $this->notify($event, self::STORE_KEY)->assertOk();
            $this->assertNotSettled($order, $tx);
        }

        $this->notify($event, self::STORE_KEY)->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSettledOnce($order, $tx);
        $this->assertSame('mt-'.$tx->id, Transaction::find($tx->id)->payment_id);

        $this->notify($event, self::STORE_KEY)->assertOk();
        $this->assertSettledOnce($order, $tx);
        Http::assertSentCount(4);
    }

    public function test_return_settles_when_paid_else_shows_processing(): void
    {
        [$store, $order, $tx] = $this->orderTx();
        $this->fakeStatus($this->mtStatus($tx, 'pending'), $this->mtStatus($tx));

        $this->get(route('ipn', 'midtrans').'?order_id='.$tx->id)->assertRedirect(route('publicView', $store->slug).'?return=success');
        $this->assertNotSettled($order, $tx);

        $this->get(route('ipn', 'midtrans').'?order_id='.$tx->id)->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_return_for_a_plan_or_unknown_order(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $tx] = $this->makePlanTransaction(10000.0, 'IDR');
        $this->fakeStatus($this->mtStatus($tx, 'pending'));
        $this->get(route('ipn', 'midtrans').'?order_id='.$tx->id)->assertRedirect(route('subscription'));
        $this->assertSame('success', session('app_alert_type'));

        $this->get(route('ipn', 'midtrans').'?order_id=99999999')->assertRedirect();
        $this->assertSame('error', session('app_alert_type'));
    }

    public function test_refund(): void
    {
        [, $tx] = $this->makePlanTransaction(10000.0, 'IDR', 'wallet_topup');
        $tx->update(['status' => Transaction::STATUS_SUCCESS]);
        Http::fake(['api.midtrans.com/v2/*/refund' => fn (ClientRequest $r) => Http::response(['status_code' => '200', 'refund_key' => $r['refund_key']])]);

        $result = MidtransController::adapter()->refund($tx->fresh(), 5000.0);
        $this->assertTrue($result['ok']);
        $this->assertStringStartsWith('refund-'.$tx->id.'-', (string) $result['reference']);
        Http::assertSent(fn (ClientRequest $r) => $r['amount'] === 5000);
    }
}
