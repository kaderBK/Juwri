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
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\CcavenueController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\EcashController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\LemonSqueezyController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\MollieController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaystackController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaytabsController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PayumoneyController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\QrpayController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\RazorpayController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\StripeController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TabbyController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TamaraController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TapController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TelrController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\CcavenueAdapter;
use Juwri\Modules\Payments\Services\MorPaymentGuard;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P1-05 slice 9c (characterisation): the exact amount + currency every gateway adapter SENDS for a transaction,
 * pinned BEFORE any adapter takes its amount from Money (GatewayMinorUnits::fromMoney & co) and kept green after it.
 *
 * Per gateway: representative 0-, 2- and 3-decimal currencies (where the gateway takes them) and the "currency
 * moved" case — the store's / platform's CURRENT currency ($gw) is not the currency the transaction was stamped with
 * ($tx). Slice 9c pinned the old behaviour there (the major NUMBER re-read in the current currency: a USD 12.50
 * checkout sent as KWD 12.500). Gateway-currency bug fix (Lane C, 2026-10-01): the "moved" rows now expect the
 * charge in the transaction's OWN currency and amount (or a refusal where the gateway cannot charge it — Paystack
 * JPY); every same-currency row is unchanged. More cases: GatewayChargeCurrencyTest.
 */
class GatewayAmountCharacterisationTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
    }

    /** A pending platform-context transaction stamped in $txCurrency, with the platform currency set to $gatewayCurrency. */
    private function planTx(float $amount, string $txCurrency, string $gatewayCurrency, string $method = 'wallet_topup'): Transaction
    {
        [$user, $tx] = $this->makePlanTransaction($amount, $txCurrency, $method);
        config(['settings.currency_code' => $gatewayCurrency]);
        $this->actingAs($user);

        return Transaction::find($tx->id);
    }

    /** The data of the (last) outgoing HTTP request whose URL contains $needle. */
    private function sent(string $needle): array
    {
        $hits = Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), $needle));
        $this->assertNotEmpty($hits, "no request to {$needle}");

        return $hits->last()[0]->data();
    }

    // ------------------------------------------------------------------ Stripe (minor units)

    public static function stripeCases(): array
    {
        return [
            'JPY 0-dec' => [1250.0, 'JPY', 'JPY', 1250],
            'USD 2-dec' => [12.34, 'USD', 'USD', 1234],
            // Stripe needs the last fils digit 0 — owner decision: charged rounded DOWN to the step, the difference
            // booked (GatewayRounding). before: 1240 (half-up), then null (refused).
            'KWD 3-dec, x10 rule' => [1.235, 'KWD', 'KWD', 1230],
            'KWD 3-dec, last digit 0' => [1.23, 'KWD', 'KWD', 1230],
            'moved USD -> KWD' => [12.5, 'USD', 'KWD', 1250],
            'moved JPY -> USD' => [1250.0, 'JPY', 'USD', 1250],
            'moved KWD -> USD' => [1.234, 'KWD', 'USD', 1230], // before: null (refused)
        ];
    }

    #[DataProvider('stripeCases')]
    public function test_stripe_checkout_unit_amount(float $amount, string $tx, string $gw, ?int $expected): void
    {
        PaymentGateway::create(['payment_folder' => 'stripe', 'payment_title' => 'Stripe', 'payment_install' => '1']);
        config(['settings.stripe_secret_key' => 'sk_test_fake', 'settings.stripe_payment_mode' => 'one_time']);
        $transaction = $this->planTx($amount, $tx, $gw);
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'stop']], 400)]);

        StripeController::pay($transaction);
        if ($expected === null) {
            Http::assertNothingSent();

            return;
        }

        $line = $this->sent('/checkout/sessions')['line_items'][0]['price_data'];
        $this->assertSame([$expected, strtolower($tx)], [(int) $line['unit_amount'], $line['currency']]);
    }

    public function test_stripe_order_uses_the_store_currency_and_its_return_check_matches(): void
    {
        PaymentGateway::create(['payment_folder' => 'stripe', 'payment_title' => 'Stripe', 'payment_install' => '1']);
        [$store, $order, $tx] = $this->makeOrderTransaction('stripe', 12.345, ['secret_key' => 'sk_test_fake_store'], 'OMR');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'OMR');
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_c1', 'url' => 'https://checkout.stripe.test/cs_c1'])]);

        StripeController::pay(Transaction::find($tx->id));
        // Owner decision: rounded DOWN to Stripe's 10-fils step (before: 12350 half-up, then refused).
        $this->assertSame(12340, (int) $this->sent('/checkout/sessions')['line_items'][0]['price_data']['unit_amount']);

        $session = fn (int $total) => Http::response(['id' => 'cs_c1', 'object' => 'checkout.session', 'mode' => 'payment',
            'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => $total, 'currency' => 'omr', 'payment_intent' => 'pi_1']);
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_c1' => Http::sequence()->pushResponse($session(12345))->pushResponse($session(12350))->pushResponse($session(12340))]);
        $this->get(route('ipn', 'stripe').'?payment_id=cs_c1&pay_mode=one_time');
        $this->assertNotSettled($order, $tx);
        $this->get(route('ipn', 'stripe').'?payment_id=cs_c1&pay_mode=one_time');
        $this->assertNotSettled($order, $tx); // before (half-up era): settled
        $this->get(route('ipn', 'stripe').'?payment_id=cs_c1&pay_mode=one_time');
        $this->assertSettledOnce($order, $tx); // the rounded-down charge settles
    }

    public function test_stripe_recurring_plan_amount_uses_the_platform_currency(): void
    {
        PaymentGateway::create(['payment_folder' => 'stripe', 'payment_title' => 'Stripe', 'payment_install' => '1']);
        config(['settings.stripe_secret_key' => 'sk_test_fake', 'settings.stripe_payment_mode' => 'recurring']);
        $transaction = $this->planTx(9.995, 'KWD', 'KWD', 'membership');
        $transaction->update(['product_id' => 7, 'frequency' => 'MONTHLY', 'product_name' => 'Pro']);
        Http::fake(fn (ClientRequest $r) => $r->method() === 'GET'
            ? Http::response(['error' => ['message' => 'No such object']], 404)
            : Http::response(['id' => 'x', 'url' => 'https://checkout.stripe.test/x']));

        StripeController::pay($transaction);

        // Owner decision: rounded DOWN (before: 7_MONTHLY_10000_KWD at 10000 — KWD 9.995 charged 10.000; then refused).
        $plan = $this->sent('/plans');
        $this->assertSame(['7_MONTHLY_9990_KWD', 9990, 'kwd'], [$plan['id'], (int) $plan['amount'], $plan['currency']]);
    }

    // ------------------------------------------------------------------ Paystack / Razorpay / PayU (minor units)

    public static function paystackCases(): array
    {
        return [
            'NGN 2-dec' => [1234.56, 'NGN', 'NGN', 123456],
            'USD 2-dec' => [12.34, 'USD', 'USD', 1234],
            'moved JPY -> NGN (Paystack cannot charge JPY: refused)' => [1250.0, 'JPY', 'NGN', null],
        ];
    }

    #[DataProvider('paystackCases')]
    public function test_paystack_widget_amount(float $amount, string $tx, string $gw, ?int $expected): void
    {
        PaymentGateway::create(['payment_folder' => 'paystack', 'payment_title' => 'Paystack', 'payment_install' => '1']);
        config(['settings.paystack_public_key' => 'pk_test_fake', 'settings.paystack_secret_key' => 'sk_test_fake']);
        $transaction = $this->planTx($amount, $tx, $gw);
        request()->merge(['email' => 'buyer@example.test']);

        $response = TestResponse::fromBaseResponse(PaystackController::pay($transaction));
        if ($expected === null) {
            $response->assertRedirect();

            return;
        }
        $details = $response->assertOk()->viewData('details');

        $this->assertSame([$expected, $tx], [$details['amount'], $details['currency']]);
    }

    public static function razorpayCases(): array
    {
        return [
            'INR 2-dec' => [499.5, 'INR', 'INR', 49950],
            'JPY 0-dec' => [1250.0, 'JPY', 'JPY', 1250],
            'KWD 3-dec, x10 rule' => [1.235, 'KWD', 'KWD', 1230], // before: 1240 (half-up), then null (refused) — owner: round down
            'KWD 3-dec, last digit 0' => [1.23, 'KWD', 'KWD', 1230],
            'moved JPY -> INR' => [1250.0, 'JPY', 'INR', 1250],
        ];
    }

    #[DataProvider('razorpayCases')]
    public function test_razorpay_order_amount(float $amount, string $tx, string $gw, ?int $expected): void
    {
        PaymentGateway::create(['payment_folder' => 'razorpay', 'payment_title' => 'Razorpay', 'payment_install' => '1']);
        config(['settings.razorpay_api_key' => 'rzp_test_fake', 'settings.razorpay_secret_key' => 'fake_secret']);
        $transaction = $this->planTx($amount, $tx, $gw);
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_C1'])]);
        if ($expected === null) {
            TestResponse::fromBaseResponse(RazorpayController::pay($transaction))->assertRedirect();
            Http::assertNothingSent();

            return;
        }

        $details = TestResponse::fromBaseResponse(RazorpayController::pay($transaction))->assertOk()->viewData('details');

        $order = $this->sent('/v1/orders');
        $this->assertSame([$expected, $tx, $expected], [$order['amount'], $order['currency'], $details['amount']]);
    }

    public function test_razorpay_webhook_compares_the_same_minor_amount(): void
    {
        PaymentGateway::create(['payment_folder' => 'razorpay', 'payment_title' => 'Razorpay', 'payment_install' => '1']);
        config(['settings.razorpay_api_key' => 'rzp_test_fake', 'settings.razorpay_secret_key' => 'fake_secret',
            'settings.razorpay_webhook_secret' => 'fake_webhook_secret']);
        $transaction = $this->planTx(1.235, 'KWD', 'KWD');
        $transaction->update(['payment_id' => 'order_C2']);
        $deliver = function (int $amount) {
            $payload = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
                'id' => 'pay_C2', 'order_id' => 'order_C2', 'status' => 'captured', 'amount' => $amount, 'currency' => 'KWD']]]]);

            return $this->call('POST', route('webhook', 'razorpay'), [], [], [], ['CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $payload, 'fake_webhook_secret')], $payload);
        };

        $deliver(1235);
        $this->assertNull(Transaction::find($transaction->id)->status, '1235 is not a Razorpay KWD amount');
        $deliver(1240);
        $this->assertNull(Transaction::find($transaction->id)->status, '1240 is more than we asked for'); // before: settled
        $deliver(1230);
        $this->assertNotNull(Transaction::find($transaction->id)->status, 'the rounded-down 1230 settles KWD 1.235 (owner decision)');
    }

    public static function payumoneyCases(): array
    {
        return [
            'PLN 2-dec' => [10.5, 'PLN', 'PLN', '1050'],
            'EUR 2-dec' => [12.34, 'EUR', 'EUR', '1234'],
            'moved JPY -> PLN' => [1250.0, 'JPY', 'PLN', '1250'],
        ];
    }

    #[DataProvider('payumoneyCases')]
    public function test_payu_order_total(float $amount, string $tx, string $gw, string $expected): void
    {
        PaymentGateway::create(['payment_folder' => 'payumoney', 'payment_title' => 'PayU', 'payment_install' => '1']);
        config(['settings.payumoney_sandbox_mode' => '0', 'settings.payumoney_merchant_pos_id' => '300746',
            'settings.payumoney_signature_key' => 'fake_sig', 'settings.payumoney_oauth_client_id' => '300746',
            'settings.payumoney_oauth_client_secret' => 'fake_secret']);
        $transaction = $this->planTx($amount, $tx, $gw);
        Http::fake([
            'secure.payu.com/pl/standard/user/oauth/authorize' => Http::response(['access_token' => 'tok', 'expires_in' => 43199]),
            'secure.payu.com/api/v2_1/orders' => Http::response(['status' => ['statusCode' => 'ERROR_STOP']], 400),
        ]);

        PayumoneyController::pay($transaction);

        $body = $this->sent('/api/v2_1/orders');
        $this->assertSame([$expected, $expected, $tx], [$body['totalAmount'], $body['products'][0]['unitPrice'], $body['currencyCode']]);
    }

    // ------------------------------------------------------------------ major-unit string gateways

    public static function telrPaytabsCases(): array
    {
        return [
            'AED 2-dec' => [12.5, 'AED', 'AED', '12.50'],
            'KWD/OMR 3-dec' => [1.235, 'OMR', 'OMR', '1.235'],
            'moved OMR -> AED' => [1.234, 'OMR', 'AED', '1.234'],
            'moved JPY -> OMR' => [1250.0, 'JPY', 'OMR', '1250'],
        ];
    }

    #[DataProvider('telrPaytabsCases')]
    public function test_telr_order_amount(float $amount, string $tx, string $gw, string $expected): void
    {
        PaymentGateway::create(['payment_folder' => 'telr', 'payment_title' => 'Telr', 'payment_install' => '1']);
        config(['settings.telr_store_id' => '10001', 'settings.telr_authkey' => 'fake-authkey', 'settings.telr_sandbox_mode' => 'live']);
        $transaction = $this->planTx($amount, $tx, $gw);
        Http::fake(['secure.telr.com/gateway/order.json' => Http::response(['error' => ['message' => 'stop']])]);

        TelrController::pay($transaction);

        $order = $this->sent('gateway/order.json')['order'];
        $this->assertSame([$expected, $tx], [$order['amount'], $order['currency']]);
    }

    #[DataProvider('telrPaytabsCases')]
    public function test_paytabs_cart_amount(float $amount, string $tx, string $gw, string $expected): void
    {
        PaymentGateway::create(['payment_folder' => 'paytabs', 'payment_title' => 'PayTabs', 'payment_install' => '1']);
        config(['settings.paytabs_profile_id' => '1001', 'settings.paytabs_secret_key' => 'FAKEPLATFORMKEY', 'settings.paytabs_region' => 'ARE']);
        $transaction = $this->planTx($amount, $tx, $gw);
        Http::fake(['secure.paytabs.com/payment/request' => Http::response(['message' => 'stop'], 400)]);

        PaytabsController::pay($transaction);

        $body = $this->sent('payment/request');
        $this->assertSame([$expected, $tx], [$body['cart_amount'], $body['cart_currency']]);
    }

    public static function ccavenueCases(): array
    {
        return [
            'INR 2-dec' => [499.5, 'INR', 'INR', '499.50'],
            'KWD 3-dec' => [1.235, 'KWD', 'KWD', '1.235'],
            'JPY 0-dec' => [1250.0, 'JPY', 'JPY', '1250'],
            'moved USD -> JPY' => [12.5, 'USD', 'JPY', '12.50'],
        ];
    }

    #[DataProvider('ccavenueCases')]
    public function test_ccavenue_encrypted_amount(float $amount, string $tx, string $gw, string $expected): void
    {
        PaymentGateway::create(['payment_folder' => 'ccavenue', 'payment_title' => 'CCAvenue', 'payment_install' => '1']);
        $key = 'FAKEPLATFORMWORKINGKEY000000000C';
        config(['settings.CCAVENUE_MERCHANT_KEY' => '111111', 'settings.CCAVENUE_ACCESS_CODE' => 'AVFAKE', 'settings.CCAVENUE_WORKING_KEY' => $key]);
        $transaction = $this->planTx($amount, $tx, $gw);

        $data = TestResponse::fromBaseResponse(CcavenueController::pay($transaction))->assertOk()->viewData('data');
        parse_str((string) CcavenueAdapter::decrypt($data['encRequest'], $key), $request);

        $this->assertSame([$expected, $tx], [$request['amount'], $request['currency']]);
    }

    public static function twoCheckoutCases(): array
    {
        return [
            'USD 2-dec' => [25.0, 'USD', 'USD', '25.00'],
            'JPY 0-dec' => [1250.0, 'JPY', 'JPY', '1250'],
            'KWD 3-dec' => [1.235, 'KWD', 'KWD', '1.235'],
            'moved KWD -> USD' => [1.234, 'KWD', 'USD', '1.234'],
        ];
    }

    #[DataProvider('twoCheckoutCases')]
    public function test_two_checkout_charge_total(float $amount, string $tx, string $gw, string $expected): void
    {
        PaymentGateway::create(['payment_folder' => 'two_checkout', 'payment_title' => '2Checkout', 'payment_install' => '1']);
        config(['settings.checkout_account_number' => '901000001', 'settings.checkout_public_key' => 'PUB',
            'settings.checkout_private_key' => 'PRIV', 'settings.2checkout_sandbox_mode' => 'production']);
        $transaction = $this->planTx($amount, $tx, $gw);
        Http::fake(['www.2checkout.com/*' => Http::response(['exception' => ['errorMsg' => 'stop'], 'response' => null], 400)]);

        $this->post(route('ipn', 'two_checkout'), ['order_id' => $transaction->id, '2checkoutToken' => 'tok']);

        $body = $this->sent('/rs/authService');
        $this->assertSame([$expected, $tx], [$body['total'], $body['currency']]);
    }

    public static function mollieCases(): array
    {
        return [
            'EUR 2-dec' => [12.34, 'EUR', 'EUR', '12.34'],
            'JPY 0-dec' => [1250.0, 'JPY', 'JPY', '1250'],
            'moved EUR -> JPY' => [12.5, 'EUR', 'JPY', '12.50'],
        ];
    }

    #[DataProvider('mollieCases')]
    public function test_mollie_payment_amount(float $amount, string $tx, string $gw, string $expected): void
    {
        PaymentGateway::create(['payment_folder' => 'mollie', 'payment_title' => 'Mollie', 'payment_install' => '1']);
        config(['settings.mollie_api_key' => 'test_fakeMollieKeyForTests000000000000']);
        $transaction = $this->planTx($amount, $tx, $gw);
        $fake = Mollie::fake([CreatePaymentRequest::class => MockResponse::created(['resource' => 'payment', 'id' => 'tr_C1', 'status' => 'open',
            'amount' => ['currency' => $tx, 'value' => $expected], '_links' => ['checkout' => ['href' => 'https://pay.mollie.test/x', 'type' => 'text/html']]], 'payment')]);

        MollieController::pay($transaction);

        $sent = null;
        $fake->assertSent(function ($pending) use (&$sent) {
            $sent = json_decode((string) $pending->payload(), true);

            return true;
        });
        $this->assertSame(['currency' => $tx, 'value' => $expected], $sent['amount']);
    }

    // ------------------------------------------------------------------ Tap / Tamara (major-unit numbers)

    public static function tapCases(): array
    {
        return [
            'KWD 3-dec' => [1.235, 'KWD', 'KWD', 1.235],
            'SAR 2-dec' => [12.5, 'SAR', 'SAR', 12.5],
            'JPY 0-dec' => [1250.0, 'JPY', 'JPY', 1250.0],
            'moved KWD -> SAR' => [1.234, 'KWD', 'SAR', 1.234],
        ];
    }

    #[DataProvider('tapCases')]
    public function test_tap_charge_amount(float $amount, string $tx, string $gw, float $expected): void
    {
        config(['settings.tap_secret_key' => 'sk_test_fake']);
        $transaction = $this->planTx($amount, $tx, $gw);
        Http::fake(['api.tap.company/*' => Http::response(['id' => 'chg_1', 'transaction' => ['url' => 'https://tap.test/x']])]);

        TapController::pay($transaction);

        $body = $this->sent('/v2/charges');
        $this->assertSame([$expected, $tx], [(float) $body['amount'], $body['currency']]);
        $this->assertIsFloat($body['amount'], 'the JSON number keeps its float shape');
    }

    public static function tamaraCases(): array
    {
        return [
            'SAR 2-dec' => [250.0, 'SAR', 250.0],
            'KWD 3-dec' => [1.235, 'KWD', 1.235],
            'AED 2-dec' => [12.34, 'AED', 12.34],
        ];
    }

    #[DataProvider('tamaraCases')]
    public function test_tamara_checkout_total(float $amount, string $currency, float $expected): void
    {
        config(['settings.tamara_api_token' => 'tok', 'settings.tamara_sandbox' => true]);
        $transaction = $this->planTx($amount, $currency, 'USD', 'membership');
        Http::fake(['api-sandbox.tamara.co/checkout' => Http::response(['order_id' => 'ord_1', 'checkout_url' => 'https://tamara.co/go/1'])]);

        TamaraController::pay($transaction);

        $total = $this->sent('/checkout')['total_amount'];
        $this->assertSame([$expected, $currency], [(float) $total['amount'], $total['currency']]);
    }

    // ------------------------------------------------------------------ gateways keyed on the transaction's own currency

    public static function tabbyCases(): array
    {
        return [
            'SAR 2-dec' => [100.0, 'SAR', '100.00'],
            'KWD 3-dec' => [1.235, 'KWD', '1.235'],
            'AED 2-dec' => [12.34, 'AED', '12.34'],
        ];
    }

    #[DataProvider('tabbyCases')]
    public function test_tabby_checkout_amount(float $amount, string $currency, string $expected): void
    {
        config(['settings.tabby_secret_key' => 'sk_test_1', 'settings.tabby_public_key' => 'pk_test_1', 'settings.tabby_merchant_code' => 'mc_1']);
        $transaction = $this->planTx($amount, $currency, 'USD', 'membership');
        Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response(['payment' => ['id' => 'PAY1'], 'web_url' => 'https://checkout.tabby.ai/go/PAY1'])]);

        TabbyController::pay($transaction);

        $payment = $this->sent('/api/v2/checkout')['payment'];
        $this->assertSame([$expected, $currency], [$payment['amount'], $payment['currency']]);
    }

    public static function lemonCases(): array
    {
        return [
            'USD 2-dec' => [12.34, 'USD', 1234],
            'JPY 0-dec' => [1250.0, 'JPY', 1250],
            'KWD 3-dec, x10 rule' => [1.235, 'KWD', null], // before: 1240 — refused, never rounded
            // Lemon Squeezy sells in no 3-decimal currency (docs.lemonsqueezy.com/help/payments/currencies). before: 1230.
            'KWD 3-dec, last digit 0' => [1.23, 'KWD', null],
        ];
    }

    #[DataProvider('lemonCases')]
    public function test_lemon_squeezy_custom_price(float $amount, string $currency, ?int $expected): void
    {
        config(['settings.lemon_squeezy_api_key' => 'ls_api_test', 'settings.lemon_squeezy_store_id' => 4242,
            'settings.lemon_squeezy_variant_id' => 111, 'settings.lemon_squeezy_variant_id_onetime' => 222]);
        $transaction = $this->planTx($amount, $currency, 'USD', 'membership');
        $transaction->update(['frequency' => 'MONTHLY', 'product_name' => 'Pro']);
        Http::fake([
            // custom_price is charged in the Lemon Squeezy store's currency: here the transaction's.
            'api.lemonsqueezy.com/v1/stores/*' => Http::response(['data' => ['attributes' => ['currency' => $currency]]]),
            'api.lemonsqueezy.com/*' => Http::response(['data' => ['attributes' => ['url' => 'https://ls.test/buy']]], 201),
        ]);

        LemonSqueezyController::pay($transaction);
        if ($expected === null) {
            Http::assertNothingSent();

            return;
        }

        $attributes = $this->sent('/v1/checkouts')['data']['attributes'];
        $this->assertSame($expected, $attributes['custom_price']);
        $this->assertSame((string) $transaction->amount, $attributes['checkout_data']['custom']['amount']);
    }

    public function test_mor_guard_binds_custom_data_by_gateway_minor_units(): void
    {
        $transaction = $this->planTx(1.235, 'KWD', 'USD', 'membership');
        $custom = fn (string $amount) => (object) (['amount' => $amount] + array_map('strval', array_filter(
            $transaction->legacyAttributes(), 'is_scalar')));

        $this->assertNotNull(MorPaymentGuard::checkoutTransaction($custom('1.235')));
        $this->assertNotNull(MorPaymentGuard::checkoutTransaction($custom('1.236')), 'same 1240 after the x10 rule');
        $this->assertNull(MorPaymentGuard::checkoutTransaction($custom('1.234')), '1230 is not 1240');
    }

    public static function syrianCases(): array
    {
        return [
            'SYP 2-dec' => [1500.5, 'SYP', '1500.50'],
            'USD 2-dec' => [12.34, 'USD', '12.34'],
        ];
    }

    #[DataProvider('syrianCases')]
    public function test_ecash_checkout_amount(float $amount, string $currency, string $expected): void
    {
        [, , $tx] = $this->makeOrderTransaction('ecash', $amount, ['merchant_id' => 'M1', 'terminal_id' => 'T1', 'api_key' => 'K1'], $currency);
        Http::fake(['api.ecash-pay.com/*' => Http::response(['checkout_url' => 'https://pay.ecash-pay.com/go/9'])]);

        EcashController::pay(Transaction::find($tx->id));

        $body = $this->sent('/checkout');
        $this->assertSame([$expected, $currency], [$body['amount'], $body['currency']]);
    }

    #[DataProvider('syrianCases')]
    public function test_qrpay_create_amount(float $amount, string $currency, string $expected): void
    {
        [, , $tx] = $this->makeOrderTransaction('qrpay', $amount, ['client_id' => 'C1', 'secret_id' => 'S1'], $currency);
        Http::fake([
            'api.syrpayment.com/authentication/*' => Http::response(['access_token' => 'AT-1', 'expire_time' => 600]),
            'api.syrpayment.com/payment/create' => Http::response(['token' => 'pt', 'payment_url' => 'https://pay.syrpayment.com/qr/9']),
        ]);

        QrpayController::pay(Transaction::find($tx->id));

        $body = $this->sent('/payment/create');
        $this->assertSame([$expected, $currency], [$body['amount'], $body['currency']]);
    }
}
