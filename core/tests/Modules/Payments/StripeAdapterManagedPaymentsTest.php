<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Support\StripeManagedPayments;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\StripeController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Support\AccountPages;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

class StripeAdapterManagedPaymentsTest extends TestCase
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
            'settings.stripe_managed_payments' => true,
        ]);
    }

    private function signed(string $payload, ?int $t = null): string
    {
        $t ??= time();

        return "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, 'whsec_fake');
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function postWebhook(string $payload, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_STRIPE_SIGNATURE'] = $signature;
        }

        return $this->call('POST', route('webhook', 'stripe'), [], [], [], $server, $payload);
    }

    public function test_platform_one_time_checkout_uses_managed_payments_and_saas_tax_code(): void
    {
        [, $tx] = $this->makePlanTransaction(15.0, 'USD', 'wallet_topup');
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_mp_1',
                'url' => 'https://checkout.stripe.test/cs_mp_1',
            ]),
        ]);

        $response = StripeController::pay($tx);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://checkout.stripe.test/cs_mp_1', $response->getTargetUrl());
        Http::assertSent(function (ClientRequest $request) {
            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer sk_test_fake_platform')
                && $request->hasHeader('Stripe-Version', StripeManagedPayments::API_VERSION)
                && ($request['managed_payments']['enabled'] ?? null) === true
                && ! isset($request['payment_method_types'])
                && ($request['line_items'][0]['price_data']['product_data']['tax_code'] ?? null) === StripeManagedPayments::TAX_SAAS_BUSINESS
                && (int) $request['line_items'][0]['price_data']['unit_amount'] === 1500;
        });
    }

    public function test_platform_recurring_checkout_uses_managed_payments_and_updates_product_tax_code(): void
    {
        config(['settings.stripe_payment_mode' => 'recurring']);
        [, $tx] = $this->makePlanTransaction(9.0, 'USD', 'membership');
        $tx->update(['product_id' => 7, 'frequency' => 'MONTHLY', 'product_name' => 'Pro']);

        Http::fake(function (ClientRequest $request) {
            return match (true) {
                $request->method() === 'GET' && str_ends_with($request->url(), '/products/7') => Http::response(['id' => '7', 'name' => 'Pro']),
                $request->method() === 'GET' && str_contains($request->url(), '/plans/') => Http::response(['id' => '7_MONTHLY_900_USD']),
                str_ends_with($request->url(), '/checkout/sessions') => Http::response(['id' => 'cs_mp_sub_1', 'url' => 'https://checkout.stripe.test/cs_mp_sub_1']),
                default => Http::response(['id' => '7', 'name' => 'Pro', 'tax_code' => StripeManagedPayments::TAX_SAAS_BUSINESS]),
            };
        });

        $response = StripeController::pay($tx);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://checkout.stripe.test/cs_mp_sub_1', $response->getTargetUrl());
        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/products/7')
            && $r->hasHeader('Stripe-Version', StripeManagedPayments::API_VERSION)
            && $r['tax_code'] === StripeManagedPayments::TAX_SAAS_BUSINESS);
        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/checkout/sessions')
            && $r->hasHeader('Stripe-Version', StripeManagedPayments::API_VERSION)
            && ($r['managed_payments']['enabled'] ?? null) === true
            && ! isset($r['payment_method_types']));
    }

    public function test_store_order_never_uses_managed_payments_and_stays_on_store_key(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('stripe', 12.5, ['secret_key' => 'sk_test_fake_store'], 'USD');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');

        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_store_1',
                'url' => 'https://checkout.stripe.test/cs_store_1',
            ]),
        ]);

        $response = StripeController::pay($tx);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://checkout.stripe.test/cs_store_1', $response->getTargetUrl());
        Http::assertSent(function (ClientRequest $request) {
            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer sk_test_fake_store')
                && ! $request->hasHeader('Stripe-Version')
                && ! isset($request['managed_payments'])
                && $request['payment_method_types'] === ['card']
                && ! isset($request['line_items'][0]['price_data']['product_data']['tax_code']);
        });
    }

    public function test_async_payment_succeeded_webhook_settles_once_and_return_acknowledges_it(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $tx] = $this->makePlanTransaction(15.0, 'USD', 'wallet_topup');
        $tx->update(['payment_id' => 'cs_async_1']);

        $payload = json_encode([
            'id' => 'evt_async_1',
            'type' => 'checkout.session.async_payment_succeeded',
            'livemode' => false,
            'data' => [
                'object' => [
                    'id' => 'cs_async_1',
                    'mode' => 'payment',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => 1500,
                    'currency' => 'usd',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postWebhook($payload, $this->signed($payload))->assertOk();
        $this->assertSame(Transaction::STATUS_SUCCESS, $tx->fresh()->status);

        // Replay is idempotent.
        $this->postWebhook($payload, $this->signed($payload))->assertOk()
            ->assertJsonPath('message', 'duplicate');

        // Buyer returning after webhook settlement gets redirected to wallet with success.
        $this->get(route('ipn', 'stripe').'?payment_id=cs_async_1&pay_mode=one_time')
            ->assertRedirect(route('wallet.index'));
    }

    public function test_return_with_complete_unpaid_session_shows_confirming_pending_return(): void
    {
        [, $tx] = $this->makePlanTransaction(15.0, 'USD', 'wallet_topup');
        $tx->update(['payment_id' => 'cs_delayed_1']);

        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_delayed_1' => Http::response([
                'id' => 'cs_delayed_1',
                'mode' => 'payment',
                'status' => 'complete',
                'payment_status' => 'unpaid',
                'amount_total' => 1500,
                'currency' => 'usd',
            ]),
        ]);

        $response = $this->get(route('ipn', 'stripe').'?payment_id=cs_delayed_1&pay_mode=one_time');

        $response->assertRedirect(AccountPages::url());
        $this->assertNull($tx->fresh()->status);
    }

    public function test_already_settled_store_payment_never_redirects_to_platform_account_page(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('stripe', 20.0, ['secret_key' => 'sk_test_fake_store'], 'USD');
        $tx->update([
            'transaction_method' => 'gift_card',
            'store_id' => $store->id,
            'payment_id' => 'cs_store_gc_1',
            'status' => Transaction::STATUS_SUCCESS,
        ]);

        $response = $this->get(route('ipn', 'stripe').'?payment_id=cs_store_gc_1&pay_mode=one_time');

        // Settlement::invalid() flashes an error and redirects to AccountPages::url(), never with a success alert.
        $response->assertRedirect(AccountPages::url());
        $response->assertSessionMissing('alert_success');
    }

    public function test_checkout_session_completed_with_unpaid_payment_status_credits_nothing(): void
    {
        [, $tx] = $this->makePlanTransaction(15.0, 'USD', 'wallet_topup');
        $tx->update(['payment_id' => 'cs_unpaid_1']);

        $payload = json_encode([
            'id' => 'evt_unpaid_1',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'data' => [
                'object' => [
                    'id' => 'cs_unpaid_1',
                    'mode' => 'payment',
                    'payment_status' => 'unpaid',
                    'status' => 'complete',
                    'amount_total' => 1500,
                    'currency' => 'usd',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postWebhook($payload, $this->signed($payload))->assertOk();
        $this->assertNull($tx->fresh()->status);
    }

    public function test_async_payment_failed_webhook_marks_pending_transaction_failed_idempotently(): void
    {
        [, $tx] = $this->makePlanTransaction(15.0, 'USD', 'wallet_topup');
        $tx->update(['payment_id' => 'cs_fail_1']);

        $payload = json_encode([
            'id' => 'evt_fail_1',
            'type' => 'checkout.session.async_payment_failed',
            'livemode' => false,
            'data' => [
                'object' => [
                    'id' => 'cs_fail_1',
                    'mode' => 'payment',
                    'payment_status' => 'unpaid',
                    'status' => 'complete',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postWebhook($payload, 't=1,v1=bad')->assertStatus(400);
        $this->assertNull($tx->fresh()->status);

        $this->postWebhook($payload, $this->signed($payload))->assertOk();
        $this->assertSame('failed', Transaction::query()->whereKey($tx->id)->value('status'));

        $this->postWebhook($payload, $this->signed($payload))->assertOk()
            ->assertJsonPath('message', 'duplicate');
        $this->assertDatabaseHas('transaction', ['id' => $tx->id, 'status' => 'failed']);
    }
}
