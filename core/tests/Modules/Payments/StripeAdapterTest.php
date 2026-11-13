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
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\StripeController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06a — Stripe adapter over a faked HTTP client (no network, fake keys).
 */
class StripeAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        PaymentGateway::create(['payment_folder' => 'stripe', 'payment_title' => 'Stripe', 'payment_install' => '1']);
        config([
            'settings.stripe_secret_key' => 'sk_test_fake_platform',
            'settings.stripe_webhook_secret' => 'whsec_fake',
            'settings.currency_code' => 'USD',
            'settings.stripe_payment_mode' => 'one_time',
        ]);
    }

    private function signed(string $payload, ?int $t = null): string
    {
        $t ??= time();

        return "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, 'whsec_fake');
    }

    private function postWebhook(string $payload, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_STRIPE_SIGNATURE'] = $signature;
        }

        return $this->call('POST', route('webhook', 'stripe'), [], [], [], $server, $payload);
    }

    private function checkoutSession(string $id, int $amount, string $currency, string $paymentStatus = 'paid', string $status = 'complete'): array
    {
        return ['id' => $id, 'object' => 'checkout.session', 'mode' => 'payment', 'url' => 'https://checkout.stripe.test/'.$id,
            'payment_status' => $paymentStatus, 'status' => $status, 'amount_total' => $amount, 'currency' => strtolower($currency), 'payment_intent' => 'pi_fake_1'];
    }

    public function test_initiate_order_uses_the_store_key_and_minor_units(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('stripe', 12.5, ['secret_key' => 'sk_test_fake_store'], 'OMR');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'OMR');
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response($this->checkoutSession('cs_fake_1', 12500, 'OMR', 'unpaid', 'open'))]);

        $response = StripeController::pay($tx);

        $this->assertSame('https://checkout.stripe.test/cs_fake_1', $response->getTargetUrl());
        $this->assertSame('cs_fake_1', $tx->fresh()->payment_id);
        Http::assertSent(function (ClientRequest $request) use ($store) {
            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer sk_test_fake_store')
                && $request['mode'] === 'payment'
                && (int) $request['line_items'][0]['price_data']['unit_amount'] === 12500
                && $request['line_items'][0]['price_data']['currency'] === 'omr'
                && $request['cancel_url'] === route('publicView', $store->slug)
                && str_contains($request['success_url'], 'pay_mode=one_time');
        });
    }

    public function test_initiate_failure_goes_back_with_the_provider_message(): void
    {
        [, $tx] = $this->makePlanTransaction(10.0, 'USD', 'wallet_topup');
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401)]);

        $response = StripeController::pay($tx);

        $this->assertTrue($response->isRedirect());
        $this->assertSame('Invalid API Key provided', session('app_alert_message'));
        $this->assertNull($tx->fresh()->payment_id);
    }

    public function test_initiate_recurring_creates_product_plan_and_drops_the_pending_row(): void
    {
        config(['settings.stripe_payment_mode' => 'recurring']);
        [, $tx] = $this->makePlanTransaction(9.0, 'USD', 'membership');
        $tx->update(['product_id' => 7, 'frequency' => 'MONTHLY', 'product_name' => 'Pro']);

        Http::fake(function (ClientRequest $request) {
            return match (true) {
                $request->method() === 'GET' => Http::response(['error' => ['message' => 'No such object']], 404),
                str_ends_with($request->url(), '/checkout/sessions') => Http::response(['id' => 'cs_sub_1', 'url' => 'https://checkout.stripe.test/cs_sub_1']),
                default => Http::response(['id' => 'x']),
            };
        });

        $response = StripeController::pay($tx);

        $this->assertSame('https://checkout.stripe.test/cs_sub_1', $response->getTargetUrl());
        $this->assertNull(Transaction::find($tx->id));
        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/products') && $r['id'] === '7');
        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/plans')
            && $r['id'] === '7_MONTHLY_900_USD' && (int) $r['interval_count'] === 30 && $r['interval'] === 'day');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/checkout/sessions')
            && $r['mode'] === 'subscription' && $r['subscription_data']['metadata']['transaction_method'] === 'membership');
    }

    public function test_return_success_settles_the_order_once(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [$store, $order, $tx] = $this->makeOrderTransaction('stripe', 20.0, ['secret_key' => 'sk_test_fake_store'], 'USD');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $tx->update(['payment_id' => 'cs_fake_2']);
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_fake_2' => Http::response($this->checkoutSession('cs_fake_2', 2000, 'USD'))]);

        $this->get(route('ipn', 'stripe').'?payment_id=cs_fake_2&pay_mode=one_time')->assertOk();
        $this->assertSettledOnce($order, $tx);
        $this->assertSame('stripe', $tx->fresh()->transaction_gatway);

        // Replay: nothing pending any more.
        $this->get(route('ipn', 'stripe').'?payment_id=cs_fake_2&pay_mode=one_time')->assertRedirect(route('subscription'));
        $this->assertSettledOnce($order, $tx);
    }

    public function test_return_wallet_top_up_redirects_to_the_wallet(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $tx] = $this->makePlanTransaction(15.0, 'USD', 'wallet_topup');
        $tx->update(['payment_id' => 'cs_fake_3']);
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_fake_3' => Http::response($this->checkoutSession('cs_fake_3', 1500, 'USD'))]);

        $this->get(route('ipn', 'stripe').'?payment_id=cs_fake_3&pay_mode=one_time')->assertRedirect(route('wallet.index'));
        $this->assertSame(Transaction::STATUS_SUCCESS, $tx->fresh()->status);
        $this->assertNotNull($tx->fresh()->credited_at);
    }

    public function test_return_unpaid_or_short_session_does_not_settle(): void
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('stripe', 20.0, ['secret_key' => 'sk_test_fake_store'], 'USD');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $tx->update(['payment_id' => 'cs_fake_4']);

        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_fake_4' => Http::sequence()
            ->push($this->checkoutSession('cs_fake_4', 2000, 'USD', 'unpaid', 'open'))
            ->push($this->checkoutSession('cs_fake_4', 100, 'USD'))]);

        $this->get(route('ipn', 'stripe').'?payment_id=cs_fake_4&pay_mode=one_time')->assertRedirect();
        $this->assertNotSettled($order, $tx);
        $this->get(route('ipn', 'stripe').'?payment_id=cs_fake_4&pay_mode=one_time')->assertRedirect();
        $this->assertNotSettled($order, $tx);
        $this->assertSame('error', session('app_alert_type'));
    }

    public function test_webhook_signature_valid_and_invalid(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $tx] = $this->makePlanTransaction(15.0, 'USD', 'wallet_topup');
        $tx->update(['payment_id' => 'cs_fake_5']);
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => $this->checkoutSession('cs_fake_5', 1500, 'USD')]]);

        $this->postWebhook($payload, null)->assertStatus(400);
        $this->postWebhook($payload, $this->signed($payload, time() - 3600))->assertStatus(400);
        $this->postWebhook($payload, 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, 'whsec_other'))->assertStatus(400);
        $this->assertNull($tx->fresh()->status);

        $this->postWebhook($payload, $this->signed($payload))->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSame(Transaction::STATUS_SUCCESS, $tx->fresh()->status);
        $this->assertNotNull($tx->fresh()->credited_at);
    }

    public function test_webhook_invoice_paid_hands_metadata_to_process_webhook(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $payload = json_encode(['type' => 'invoice.paid', 'data' => ['object' => [
            'id' => 'in_fake_1', 'subscription' => 'sub_fake_1', 'metadata' => [], 'amount_paid' => 1500, 'currency' => 'usd',
            'lines' => ['data' => [['metadata' => ['user_id' => '999999', 'transaction_method' => 'membership', 'product_id' => '1', 'amount' => '15.00', 'currency_code' => 'USD']]]],
        ]]]);

        // Unknown account in the metadata: processWebhook refuses with 400.
        $this->postWebhook($payload, $this->signed($payload))->assertStatus(400)->assertJson(['status' => 400]);

        // BL-038 P1: an invoice whose metadata names no amount it was sold at is never settled: held for review.
        $noAmount = json_encode(['type' => 'invoice.paid', 'data' => ['object' => [
            'id' => 'in_fake_3', 'subscription' => 'sub_fake_1', 'metadata' => [], 'amount_paid' => 1500, 'currency' => 'usd',
            'lines' => ['data' => [['metadata' => ['user_id' => '999999', 'transaction_method' => 'membership', 'product_id' => '1']]]],
        ]]]);
        $this->postWebhook($noAmount, $this->signed($noAmount))->assertOk()->assertJson(['message' => 'held for review']);
        $this->assertSame(Transaction::STATUS_REVIEW, Transaction::query()->where('payment_id', 'review:in_fake_3')->value('status'));

        $empty = json_encode(['type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_fake_2', 'metadata' => []]]]);
        $this->postWebhook($empty, $this->signed($empty))->assertStatus(400);
    }

    public function test_refund_uses_the_session_payment_intent(): void
    {
        [, $tx] = $this->makePlanTransaction(15.0, 'USD', 'wallet_topup');
        $tx->update(['payment_id' => 'cs_fake_6', 'status' => Transaction::STATUS_SUCCESS]);
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_fake_6' => Http::response($this->checkoutSession('cs_fake_6', 1500, 'USD')),
            'api.stripe.com/v1/refunds' => Http::response(['id' => 're_fake_1', 'status' => 'succeeded']),
        ]);

        $result = StripeController::adapter()->refund($tx->fresh(), 5.0);

        $this->assertSame(['ok' => true, 'reference' => 're_fake_1', 'message' => null], $result);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/refunds') && $r['payment_intent'] === 'pi_fake_1' && (int) $r['amount'] === 500);
    }
}
