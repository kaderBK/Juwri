<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\FatoraController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\MollieController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\NoonController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\StripeController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Gateway decimals checked against the providers' own docs (quoted in GatewayMinorUnits). Characterisation first: these assertions pin what each gateway is sent today; the fix flips the
 * ones the docs contradict, with `before:` comments. Stripe/Razorpay/Lemon Squeezy 3-decimal sends and the Noon/Paymob
 * sends are pinned in GatewayAmountCharacterisationTest / GatewayExactAmountTest (flipped there).
 *
 * Fix: Mollie ISK whole and HUF/TWD only in whole units (its PayPal method rounds them up); Fatora only QAR/USD (it
 * converts anything else to USD); Noon 3-decimal currencies at 3 decimals (documented); Stripe/Razorpay/Lemon Squeezy
 * refuse a 3-decimal amount whose last digit is not 0 instead of rounding it (GatewayMinorUnits::GATEWAY_STEP_DECIMALS).
 */
class GatewayDocumentedDecimalsTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        app()->setLocale('en');
    }

    private function planTx(float $amount, string $currency): Transaction
    {
        [$user, $tx] = $this->makePlanTransaction($amount, $currency, 'wallet_topup');
        config(['settings.currency_code' => $currency]);
        $this->actingAs($user);

        return Transaction::find($tx->id);
    }

    // ------------------------------------------------------------------ Mollie (docs.mollie.com/docs/multicurrency)

    /** @return array<string, array{0: float, 1: string, 2: ?string}> */
    public static function mollieCases(): array
    {
        return [
            'ISK whole' => [500.0, 'ISK', '500'], // before: '500.00' (Mollie: ISK has 0 decimals)
            'HUF whole' => [1500.0, 'HUF', '1500.00'],
            'HUF fraction' => [1500.5, 'HUF', null], // before: '1500.50' (Mollie rounds PayPal forint payments up to 1501)
            'EUR' => [12.34, 'EUR', '12.34'],
        ];
    }

    #[DataProvider('mollieCases')]
    public function test_mollie_payment_value(float $amount, string $currency, ?string $expected): void
    {
        PaymentGateway::create(['payment_folder' => 'mollie', 'payment_title' => 'Mollie', 'payment_install' => '1']);
        config(['settings.mollie_api_key' => 'test_fakeMollieKeyForTests000000000000']);
        $transaction = $this->planTx($amount, $currency);
        $fake = Mollie::fake([CreatePaymentRequest::class => MockResponse::created(['resource' => 'payment', 'id' => 'tr_D1', 'status' => 'open',
            'amount' => ['currency' => $currency, 'value' => '1'], '_links' => ['checkout' => ['href' => 'https://pay.mollie.test/x', 'type' => 'text/html']]], 'payment')]);

        MollieController::pay($transaction);

        if ($expected === null) {
            $fake->assertSentCount(0);
            $this->assertSame('This payment method only charges whole amounts in HUF, and this payment is 1500.50 HUF. Please choose another payment method.', session('app_alert_message'));

            return;
        }
        $sent = null;
        $fake->assertSent(function ($pending) use (&$sent) {
            $sent = json_decode((string) $pending->payload(), true);

            return true;
        });
        $this->assertSame($expected, $sent['amount']['value'] ?? null);
    }

    public function test_mollie_isk_refund_value(): void
    {
        PaymentGateway::create(['payment_folder' => 'mollie', 'payment_title' => 'Mollie', 'payment_install' => '1']);
        config(['settings.mollie_api_key' => 'test_fakeMollieKeyForTests000000000000']);
        $tx = $this->planTx(500.0, 'ISK');
        $tx->update(['payment_id' => 'tr_D2', 'status' => Transaction::STATUS_SUCCESS]);
        $fake = Mollie::fake([CreatePaymentRefundRequest::class => MockResponse::created(['resource' => 'refund', 'id' => 're_D2', 'status' => 'pending',
            'amount' => ['currency' => 'ISK', 'value' => '100'], 'paymentId' => 'tr_D2'])]);

        $this->assertTrue(MollieController::adapter()->refund($tx->fresh(), 100.0)['ok']);
        $sent = null;
        $fake->assertSent(function ($pending) use (&$sent) {
            $sent = json_decode((string) $pending->payload(), true);

            return true;
        });
        $this->assertSame('100', $sent['amount']['value']); // before: '100.00'
    }

    // ------------------------------------------------------------------ Stripe ISK (docs.stripe.com/currencies, Special cases)

    public function test_stripe_isk_is_sent_as_a_two_decimal_value(): void
    {
        PaymentGateway::create(['payment_folder' => 'stripe', 'payment_title' => 'Stripe', 'payment_install' => '1']);
        config(['settings.stripe_secret_key' => 'sk_test_fake', 'settings.stripe_payment_mode' => 'one_time']);
        $transaction = $this->planTx(5.0, 'ISK');
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'stop']], 400)]);

        StripeController::pay($transaction);

        // "to charge 5 ISK, provide an amount value of 500" — unchanged by the fix.
        Http::assertSent(fn (ClientRequest $r) => (int) ($r['line_items'][0]['price_data']['unit_amount'] ?? -1) === 500);
    }

    // ------------------------------------------------------------------ Fatora (fatora.io/api/currencySupport.php)

    /** @return array<string, array{0: string, 1: ?float}> */
    public static function fatoraCases(): array
    {
        // before: KWD and SAR were sent too (12.5) — Fatora converts every currency but QAR/USD to USD.
        return ['QAR' => ['QAR', 12.5], 'USD' => ['USD', 12.5], 'KWD' => ['KWD', null], 'SAR' => ['SAR', null]];
    }

    #[DataProvider('fatoraCases')]
    public function test_fatora_checkout_currency(string $currency, ?float $expected): void
    {
        PaymentGateway::firstOrCreate(['payment_folder' => 'fatora'], ['payment_title' => 'Fatora', 'payment_install' => '1']);
        [$store, , $tx] = $this->makeOrderTransaction('fatora', 12.5, ['api_key' => 'KEY'], $currency);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        Http::fake(['*' => Http::response(['message' => 'stop'], 400)]);
        request()->merge(['email' => 'a@example.com', 'phone' => '123', 'name' => 'A']);

        FatoraController::pay(Transaction::find($tx->id));

        $hit = Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), '/payments/checkout'))->last();
        $this->assertSame($expected, $hit === null ? null : $hit[0]->data()['amount']);
        if ($expected === null) {
            $this->assertSame("This payment method cannot charge in {$currency}. Please choose another payment method.", session('app_alert_message'));
        }
    }

    // ------------------------------------------------------------------ Stripe / Razorpay / Lemon Squeezy 3-decimal step

    public function test_stripe_charges_a_kwd_amount_between_steps_rounded_down(): void
    {
        PaymentGateway::create(['payment_folder' => 'stripe', 'payment_title' => 'Stripe', 'payment_install' => '1']);
        config(['settings.stripe_secret_key' => 'sk_test_fake', 'settings.stripe_payment_mode' => 'one_time']);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_k', 'url' => 'https://checkout.stripe.test/cs_k'])]);

        StripeController::pay($this->planTx(12.345, 'KWD'));
        // Owner decision (GatewayRounding): before: refused (and before that 12350, half-up).
        Http::assertSent(fn (ClientRequest $r) => (int) $r['line_items'][0]['price_data']['unit_amount'] === 12340);

        StripeController::pay($this->planTx(12.34, 'KWD'));
        Http::assertSent(fn (ClientRequest $r) => (int) $r['line_items'][0]['price_data']['unit_amount'] === 12340);
    }

    public function test_the_step_rules(): void
    {
        $kwd = Money::ofMinor(12345, 'KWD');
        foreach (['stripe', 'razorpay', 'lemon_squeezy'] as $gateway) {
            $this->assertSame(2, GatewayMinorUnits::gatewayPrecision($gateway, 'KWD'), $gateway);
            $this->assertNull(GatewayMinorUnits::gatewayMinor($gateway, $kwd), $gateway); // before: 12350
            $this->assertSame(12340, GatewayMinorUnits::gatewayMinor($gateway, Money::ofMinor(12340, 'KWD')), $gateway);
        }
        $this->assertSame(3, GatewayMinorUnits::gatewayPrecision('tap', 'KWD'));
        $this->assertSame(1234, GatewayMinorUnits::gatewayMinor('stripe', Money::ofMinor(1234, 'USD')));
        $this->assertSame(0, GatewayMinorUnits::gatewayPrecision('stripe', 'ISK'));
        $this->assertSame(500, GatewayMinorUnits::gatewayMinor('stripe', Money::ofMinor(5, 'ISK')));
        $this->assertSame(0, GatewayMinorUnits::gatewayPrecision('mollie', 'HUF'));
        $this->assertSame('1500.00', GatewayMinorUnits::gatewayMajorString('mollie', Money::ofMinor(150000, 'HUF'))); // format stays 2 decimals
        $this->assertSame(2, GatewayMinorUnits::gatewayPrecision('stripe', 'HUF')); // Stripe charges HUF/TWD at 2 decimals
        $this->assertSame('12.345', GatewayMinorUnits::gatewayMajorString('noon', $kwd)); // before: null (noon was capped at 2)
        $this->assertNull(GatewayMinorUnits::gatewayMajorString('geidea', $kwd));
    }

    public function test_noon_sends_omr_at_3_decimals(): void
    {
        PaymentGateway::firstOrCreate(['payment_folder' => 'noon'], ['payment_title' => 'Noon', 'payment_install' => '1']);
        [$store, , $tx] = $this->makeOrderTransaction('noon', 12.345, ['business_id' => 'BUS', 'application_id' => 'APP', 'api_key' => 'KEY'], 'OMR');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'OMR');
        Http::fake(['*' => Http::response(['message' => 'stop'], 400)]);

        NoonController::pay(Transaction::find($tx->id));

        $hit = Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), '/order'))->last();
        $this->assertSame([12.345, 'OMR'], [$hit[0]->data()['order']['amount'], $hit[0]->data()['order']['currency']]); // before: not sent (refused)
    }
}
