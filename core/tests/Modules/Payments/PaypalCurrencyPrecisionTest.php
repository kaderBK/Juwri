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
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaypalController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayCurrencies;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * PayPal currency precision. PayPal REST takes HUF, TWD and JPY with NO decimals (its own rule — ISO gives HUF/TWD 2),
 * now in GatewayMinorUnits::GATEWAY_ZERO_DECIMAL. Whole amounts are sent without decimals; an amount with a fraction
 * PayPal cannot carry (HUF 1500.50) is refused before any PayPal call — the customer's amount is never rounded.
 * Capture, refund and renewal-webhook comparisons use the same rule.
 *
 * `before:` comments record what the same assertion returned before the fix.
 */
class PaypalCurrencyPrecisionTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $routes = [];

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        app()->setLocale('en'); // messages are asserted in English (the test app's default locale is not)
        PaymentGateway::create(['payment_folder' => 'paypal', 'payment_title' => 'PayPal', 'payment_install' => '1']);
        config(['settings.paypal_api_client_id' => 'fake-platform-client', 'settings.paypal_api_secret' => 'fake-platform-secret',
            'settings.paypal_sandbox_mode' => 'Yes', 'settings.paypal_payment_mode' => 'one_time', 'settings.currency_code' => 'HUF',
            'settings.site_title' => 'juwri', 'settings.paypal_webhook_id' => 'WH-ID-FAKE']); // Phase 4 item 18: notifications must be signed
    }

    /** @param array<string, mixed> $routes */
    private function fakePaypal(array $routes): void
    {
        $this->routes = $routes;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 300]);
            }
            foreach ($this->routes as $suffix => $answer) {
                [$method, $path] = explode(' ', $suffix, 2);
                if ($request->method() === $method && str_contains($request->url(), $path)) {
                    return is_array($answer) && array_key_exists('__status', $answer)
                        ? Http::response($answer['body'], $answer['__status'])
                        : Http::response($answer);
                }
            }

            return Http::response(['message' => 'unexpected'], 500);
        });
    }

    private function orderTx(float $amount, string $currency): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('paypal', $amount, ['api_client_id' => 'fake-store-client', 'api_secret' => 'fake-store-secret'], $currency);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return [$store, $order, $tx];
    }

    private function approve(): array
    {
        return ['id' => 'ORDER-1', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.test/x']]];
    }

    /** @return array<string, string>|null the purchase unit amount PayPal was sent, null when no order was created */
    private function orderAmountSent(Transaction $tx): ?array
    {
        $this->fakePaypal(['POST /v2/checkout/orders' => $this->approve()]);
        PaypalController::pay($tx);
        $sent = Http::recorded(fn (ClientRequest $r) => str_ends_with($r->url(), '/v2/checkout/orders'))->last();

        return $sent === null ? null : $sent[0]['purchase_units'][0]['amount'];
    }

    public function test_huf_and_twd_whole_amounts_are_sent(): void
    {
        $this->assertSame(['currency_code' => 'HUF', 'value' => '1500'], $this->orderAmountSent($this->orderTx(1500, 'HUF')[2])); // before: '1500.00'
        $this->assertSame(['currency_code' => 'TWD', 'value' => '300'], $this->orderAmountSent($this->orderTx(300, 'TWD')[2])); // before: '300.00'
    }

    public function test_jpy_usd_and_kwd_are_unchanged(): void
    {
        $this->assertSame('1200', $this->orderAmountSent($this->orderTx(1200, 'JPY')[2])['value']);
        $this->assertSame('12.50', $this->orderAmountSent($this->orderTx(12.5, 'USD')[2])['value']);
        $this->assertSame('12.345', $this->orderAmountSent($this->orderTx(12.345, 'KWD')[2])['value']);
    }

    private function refusal(string $amount, string $currency): string
    {
        return "This payment method only charges whole amounts in {$currency}, and this payment is {$amount} {$currency}. Please choose another payment method.";
    }

    public function test_a_fractional_huf_order_is_refused_not_rounded(): void
    {
        [, $order, $tx] = $this->orderTx(1500.5, 'HUF');

        $this->assertNull($this->orderAmountSent($tx)); // before: ['currency_code' => 'HUF', 'value' => '1500.50']
        Http::assertNothingSent(); // not even the OAuth token
        $this->assertSame($this->refusal('1500.50', 'HUF'), session('app_alert_message'));
        $this->assertNull($tx->fresh()->payment_id); // before: 'ORDER-1'
        $this->assertNotSettled($order, $tx);
    }

    public function test_a_fractional_twd_wallet_top_up_is_refused(): void
    {
        [, $tx] = $this->makePlanTransaction(99.5, 'TWD', 'wallet_topup');
        config(['settings.currency_code' => 'TWD']);

        $this->assertNull($this->orderAmountSent($tx)); // before: ['currency_code' => 'TWD', 'value' => '99.50']
        $this->assertSame($this->refusal('99.50', 'TWD'), session('app_alert_message'));
    }

    public function test_the_refusal_is_translated(): void
    {
        [, $tx] = $this->makePlanTransaction(99.5, 'HUF', 'wallet_topup');
        app()->setLocale('fr');

        $this->assertSame("Ce moyen de paiement n'encaisse que des montants entiers en HUF, et ce paiement est de 99.50 HUF. Veuillez choisir un autre moyen de paiement.",
            GatewayCurrencies::refusal('paypal', $tx));
        $this->assertNull(GatewayCurrencies::refusal('stripe', $tx)); // Stripe takes HUF with 2 decimals
    }

    public function test_a_whole_huf_capture_settles(): void
    {
        [, $order, $tx] = $this->orderTx(1500, 'HUF');
        $tx->update(['payment_id' => 'ORDER-H']);
        $this->fakePaypal(['POST /v2/checkout/orders/ORDER-H/capture' => ['id' => 'ORDER-H', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-H', 'amount' => ['value' => '1500', 'currency_code' => 'HUF']]]]]]]]);

        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORDER-H')->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_a_huf_capture_one_forint_short_does_not_settle(): void
    {
        [, $order, $tx] = $this->orderTx(1500, 'HUF');
        $tx->update(['payment_id' => 'ORDER-S']);
        $this->fakePaypal(['POST /v2/checkout/orders/ORDER-S/capture' => ['id' => 'ORDER-S', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-S', 'amount' => ['value' => '1499', 'currency_code' => 'HUF']]]]]]]]);

        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORDER-S')->assertRedirect();
        $this->assertNotSettled($order, $tx);
    }

    private function recurringRoutes(): array
    {
        return [
            'GET /v1/catalogs/products/' => ['id' => 'product_x'],
            'POST /v1/billing/plans' => ['id' => 'P-FAKE1'],
            'POST /v1/billing/subscriptions' => ['id' => 'I-FAKE1', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.test/sub']]],
        ];
    }

    private function planPriceSent(): ?array
    {
        $sent = Http::recorded(fn (ClientRequest $r) => str_ends_with($r->url(), '/v1/billing/plans'))->first();

        return $sent === null ? null : $sent[0]['billing_cycles'][0]['pricing_scheme']['fixed_price'];
    }

    public function test_recurring_huf_plan_price(): void
    {
        config(['settings.paypal_payment_mode' => 'recurring']);
        [, $tx] = $this->makePlanTransaction(1500, 'HUF', 'membership');
        $tx->update(['product_id' => 7, 'frequency' => 'MONTHLY', 'product_name' => 'Pro']);
        $this->fakePaypal($this->recurringRoutes());

        PaypalController::pay($tx);

        $this->assertSame(['value' => '1500', 'currency_code' => 'HUF'], $this->planPriceSent()); // before: '1500.00'
        $this->assertNull(Transaction::find($tx->id));
    }

    public function test_a_fractional_monthly_huf_add_on_is_refused(): void
    {
        [, $tx] = $this->makePlanTransaction(1500.5, 'HUF', 'addon');
        $tx->forceFill(['frequency' => 'MONTHLY', 'product_name' => 'Extra', 'details' => ['type' => 'sms']])->save();
        $this->fakePaypal($this->recurringRoutes());

        PaypalController::pay($tx);

        $this->assertNull($this->planPriceSent()); // before: ['value' => '1500.50', 'currency_code' => 'HUF']
        Http::assertNothingSent();
        $this->assertNotNull(Transaction::find($tx->id)); // before: deleted (subscription started)
        $this->assertSame($this->refusal('1500.50', 'HUF'), session('app_alert_message'));
    }

    private function settledHufTx(float $amount): Transaction
    {
        [, $tx] = $this->makePlanTransaction($amount, 'HUF', 'wallet_topup');
        $tx->update(['payment_id' => 'ORDER-R', 'status' => Transaction::STATUS_SUCCESS]);
        $this->fakePaypal([
            'GET /v2/checkout/orders/ORDER-R' => ['id' => 'ORDER-R', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-R']]]]]],
            'POST /v2/payments/captures/CAP-R/refund' => ['id' => 'REF-R', 'status' => 'COMPLETED'],
        ]);

        return $tx->fresh();
    }

    /** @return list<mixed> */
    private function refundAmountsSent(): array
    {
        return Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), '/refund'))->map(fn (array $p) => $p[0]['amount'])->values()->all();
    }

    public function test_huf_refunds(): void
    {
        $tx = $this->settledHufTx(1500);

        $this->assertTrue(PaypalController::adapter()->refund($tx, 500.0)['ok']);
        $fraction = PaypalController::adapter()->refund($tx->fresh(), 100.5);
        $this->assertFalse($fraction['ok']); // before: true, sent as '100.50'
        $this->assertSame('The refund amount 100.5 cannot be refunded exactly in HUF on this payment method. Enter an amount the payment method supports.', $fraction['message']);
        $this->assertSame([['value' => '500', 'currency_code' => 'HUF']], $this->refundAmountsSent()); // before: '500.00', then '100.50'
    }

    private function saleWebhook(string $total, array $metadata): TestResponse
    {
        $this->fakePaypal([
            'POST /v1/notifications/verify-webhook-signature' => ['verification_status' => 'SUCCESS'],
            'GET /v1/payments/sale/SALE-1' => ['id' => 'SALE-1', 'state' => 'completed', 'billing_agreement_id' => 'I-1', 'amount' => ['total' => $total, 'currency' => 'HUF']],
            'GET /v1/billing/subscriptions/I-1' => ['id' => 'I-1', 'custom_id' => http_build_query($metadata)],
        ]);
        $event = json_encode(['id' => 'WH-1', 'event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => ['id' => 'SALE-1', 'billing_agreement_id' => 'I-1']]);

        return $this->call('POST', route('webhook', 'paypal'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA', 'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-fake',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'T-1', 'HTTP_PAYPAL_TRANSMISSION_SIG' => 'c2lnbmF0dXJl', 'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601String()], $event);
    }

    public function test_a_renewal_sale_for_another_amount_is_rejected(): void
    {
        $meta = ['user_id' => 999999, 'product_id' => 1, 'transaction_method' => 'membership', 'currency_code' => 'HUF', 'amount' => '1500'];

        $this->saleWebhook('1400.00', $meta)->assertStatus(400);
        // before: no alert — the sale passed to processWebhook (which refused only the unknown account)
        $this->assertDatabaseHas('platform_alerts', ['kind' => 'payment', 'message' => 'paypal: webhook rejected (sale amount mismatch)']);
    }

    public function test_a_renewal_sale_for_the_plan_amount_passes_the_guard(): void
    {
        $meta = ['user_id' => 999999, 'product_id' => 1, 'transaction_method' => 'membership', 'currency_code' => 'HUF', 'amount' => '1500'];

        foreach (['1500', '1500.00'] as $total) {
            $this->saleWebhook($total, $meta)->assertStatus(400); // past the guard; the unknown account is refused by processWebhook
        }
        $this->assertDatabaseMissing('platform_alerts', ['message' => 'paypal: webhook rejected (sale amount mismatch)']);

        // A custom_id without an amount (subscriptions sold before it was carried) is not compared.
        unset($meta['amount']);
        $this->saleWebhook('1400.00', $meta)->assertStatus(400);
        $this->assertDatabaseMissing('platform_alerts', ['message' => 'paypal: webhook rejected (sale amount mismatch)']);
    }
}
