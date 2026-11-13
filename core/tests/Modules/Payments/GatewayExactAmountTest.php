<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\FatoraController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\GeideaController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\NoonController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaylinkController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaymobController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\NoonAdapter;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Gateway exact amounts. (1) Settling: every gateway compares the amount the provider reports with the stored amount
 * EXACTLY at the gateway's decimals through one helper, GatewayMinorUnits::paidMatches() — Settlement::amountMatches()
 * (Telr, PayTabs, CCAvenue, Iyzico, Paytm, PayU, 2Checkout, Midtrans), Mollie, Tap, Tabby, Tamara, Noon, Geidea,
 * Paylink, Fatora, Paymob, Fawry. A payment short by any minor unit never settles (before: ±0.01, or a comparison
 * after rounding to 2 decimals). (2) Sending: Geidea, Paylink, Paymob send at their own 2 decimals (Noon and Fatora dropped from that list after the
 * provider-docs check — GatewayDocumentedDecimalsTest)
 * (GatewayMinorUnits::GATEWAY_MAX_DECIMALS); an amount they cannot carry (OMR 12.345) is refused, never rounded;
 * Paymob refuses 3-decimal currencies outright (GatewayCurrencies::MAX_CURRENCY_DECIMALS).
 *
 * `before:` comments record what the same assertion returned before the fix.
 */
class GatewayExactAmountTest extends TestCase
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

    /** @return array{0: Store, 1: Order, 2: Transaction} */
    private function orderTx(string $gateway, float $amount, string $currency, array $creds): array
    {
        PaymentGateway::firstOrCreate(['payment_folder' => $gateway], ['payment_title' => ucfirst($gateway), 'payment_install' => '1']);
        [$store, $order, $tx] = $this->makeOrderTransaction($gateway, $amount, $creds, $currency);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return [$store, $order, Transaction::find($tx->id)];
    }

    // ------------------------------------------------------------------ Settlement::amountMatches (8 adapters)

    /** @return array<string, array{0: string, 1: string}> */
    public static function settlementGateways(): array
    {
        return ['telr' => ['telr', 'KWD'], 'paytabs' => ['paytabs', 'KWD'], 'ccavenue' => ['ccavenue', 'KWD'], 'iyzico' => ['iyzico', 'USD'],
            'paytm' => ['paytm', 'INR'], 'payumoney' => ['payumoney', 'PLN'], 'two_checkout' => ['two_checkout', 'USD'], 'midtrans' => ['midtrans', 'IDR']];
    }

    #[DataProvider('settlementGateways')]
    public function test_settlement_amount_matches(string $gateway, string $currency): void
    {
        [, $tx] = $this->makePlanTransaction(12.5, 'KWD', 'wallet_topup');
        $this->assertTrue(Settlement::amountMatches($tx, 12.5, 'KWD', $gateway, 'KWD'));
        $this->assertTrue(Settlement::amountMatches($tx, '12.500', 'KWD', $gateway, 'KWD'));
        $this->assertFalse(Settlement::amountMatches($tx, 12.499, 'KWD', $gateway, 'KWD')); // one fils short — before: true
        $this->assertFalse(Settlement::amountMatches($tx, 12.491, 'KWD', $gateway, 'KWD')); // nine fils short — before: true
        $this->assertFalse(Settlement::amountMatches($tx, 12.49, 'KWD', $gateway, 'KWD')); // ten fils short — before: true (float 0.00999… < 0.01)
        $this->assertFalse(Settlement::amountMatches($tx, 12.501, 'KWD', $gateway, 'KWD')); // over — before: true

        [, $two] = $this->makePlanTransaction($currency === 'IDR' ? 150000.0 : 12.5, $currency, 'wallet_topup');
        $short = $currency === 'IDR' ? 149999.995 : 12.49; // a cent short (float: |12.49 − 12.5| < 0.01); IDR half a sen short
        $this->assertFalse(Settlement::amountMatches($two, $short, $currency, $gateway, $currency)); // before: true
        $this->assertTrue(Settlement::amountMatches($two, $currency === 'IDR' ? 150000.0 : 12.5, $currency, $gateway, $currency));
    }

    // ------------------------------------------------------------------ own comparisons, end to end (KWD 12.500 paid 12.499)

    public function test_noon_settles_a_short_payment(): void
    {
        [, $order, $tx] = $this->orderTx('noon', 12.5, 'KWD', ['business_id' => 'BUS', 'application_id' => 'APP', 'api_key' => 'KEY']);
        $answer = fn ($amount) => Http::response(['result' => ['order' => ['status' => 'CAPTURED', 'reference' => (string) $tx->id, 'amount' => $amount, 'currency' => 'KWD']]]);
        Http::fake(['*/order/605208341474' => Http::sequence()->pushResponse($answer(12.499))->pushResponse($answer(12.5))]);
        $settle = fn () => app(NoonAdapter::class)->verifyAndSettle('605208341474', (string) $tx->id);

        $this->assertFalse($settle()); // before: true
        $this->assertNotSettled($order, $tx);
        $this->assertTrue($settle()); // the exact amount (noon's 12.5 for KWD 12.500)
        $this->assertSettledOnce($order, $tx);
    }

    private function geideaCallback(Transaction $tx, string $amount, string $currency): array
    {
        $fields = ['orderId' => 'G-1', 'orderAmount' => $amount, 'orderCurrency' => $currency, 'status' => 'Success', 'timestamp' => '2026-10-01T10:00:00Z', 'merchantReferenceId' => (string) $tx->id];
        $fields['signature'] = base64_encode(hash_hmac('sha256', 'PK'.$amount.$currency.'G-1'.'Success'.$tx->id.'2026-10-01T10:00:00Z', 'PASS', true));

        return $fields;
    }

    public function test_geidea_settles_a_short_payment(): void
    {
        [, $order, $tx] = $this->orderTx('geidea', 12.5, 'KWD', ['merchant_public_key' => 'PK', 'api_password' => 'PASS']);

        $this->post(route('webhook', 'geidea'), $this->geideaCallback($tx, '12.499', 'KWD'))->assertOk();
        $this->assertNotSettled($order, $tx); // before: settled
        $this->post(route('webhook', 'geidea'), $this->geideaCallback($tx, '12.50', 'KWD'))->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_paylink_settles_a_short_payment(): void
    {
        [, $order, $tx] = $this->orderTx('paylink', 12.5, 'KWD', ['api_id' => 'ID', 'secret_key' => 'SECRET']);
        $invoice = fn ($amount) => Http::response(['orderStatus' => 'Paid', 'orderNumber' => (string) $tx->id, 'amount' => $amount, 'currency' => 'KWD']);
        Http::fake(['*/api/auth' => Http::response(['id_token' => 'tok']), '*/api/getInvoice/*' => Http::sequence()->pushResponse($invoice(12.499))->pushResponse($invoice(12.5))]);

        $this->post(route('webhook', 'paylink'), ['transactionNo' => 'INV-1', 'orderNumber' => (string) $tx->id])->assertOk();
        $this->assertNotSettled($order, $tx); // before: settled
        $this->post(route('webhook', 'paylink'), ['transactionNo' => 'INV-1', 'orderNumber' => (string) $tx->id])->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_fatora_settles_a_short_payment(): void
    {
        [, $order, $tx] = $this->orderTx('fatora', 12.5, 'KWD', ['api_key' => 'KEY']);
        $verify = fn ($amount) => Http::response(['result' => ['status' => 'SUCCESS', 'amount' => $amount, 'currency' => 'KWD']]);
        Http::fake(['*/payments/verify' => Http::sequence()->pushResponse($verify(12.499))->pushResponse($verify('12.50'))]);

        $this->post(route('webhook', 'fatora'), ['order_id' => (string) $tx->id])->assertOk();
        $this->assertNotSettled($order, $tx); // before: settled
        $this->post(route('webhook', 'fatora'), ['order_id' => (string) $tx->id])->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    private function paymobHmac(array $obj, string $secret): string
    {
        $concat = '';
        foreach (['amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
            'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment', 'is_voided', 'order.id', 'owner', 'pending', 'source_data.pan',
            'source_data.sub_type', 'source_data.type', 'success'] as $field) {
            $v = data_get($obj, $field);
            $concat .= is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }

        return hash_hmac('sha512', $concat, $secret);
    }

    private function paymobWebhook(Transaction $tx, int $cents, string $currency): void
    {
        $tx->forceFill(['details' => ['paymob_order_id' => '777']])->save();
        $obj = ['amount_cents' => $cents, 'created_at' => '2026-10-01', 'currency' => $currency, 'error_occured' => false, 'has_parent_transaction' => false,
            'id' => 1, 'integration_id' => 5, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false, 'is_refunded' => false,
            'is_standalone_payment' => true, 'is_voided' => false, 'order' => ['id' => 777, 'merchant_order_id' => (string) $tx->id], 'owner' => 1,
            'pending' => false, 'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'], 'success' => true];
        $this->post('/webhook/paymob?hmac='.$this->paymobHmac($obj, 'HMAC'), ['obj' => $obj])->assertOk();
    }

    public function test_paymob_never_settles_a_3_decimal_currency(): void
    {
        [, $order, $tx] = $this->orderTx('paymob', 12.5, 'KWD', ['secret_key' => 'S', 'public_key' => 'P', 'integration_id' => '5', 'hmac_secret' => 'HMAC']);

        $this->paymobWebhook($tx, 1250, 'KWD');
        $this->assertNotSettled($order, $tx); // before: settled (unit of amount_cents for KWD unverified — could be 1.250 KWD)
    }

    public function test_paymob_settles_only_the_exact_cents(): void
    {
        $creds = ['secret_key' => 'S', 'public_key' => 'P', 'integration_id' => '5', 'hmac_secret' => 'HMAC'];
        [, $order, $tx] = $this->orderTx('paymob', 12.5, 'SAR', $creds);
        $this->paymobWebhook($tx, 1249, 'SAR');
        $this->assertNotSettled($order, $tx);
        $this->paymobWebhook($tx, 1250, 'SAR');
        $this->assertSettledOnce($order, $tx);
    }

    public function test_fawry_settles_a_sub_piastre_short_payment(): void
    {
        [, $order, $tx] = $this->orderTx('fawry', 12.5, 'EGP', ['merchant_code' => 'MC', 'security_key' => 'SK']);
        $fields = ['fawryRefNumber' => 'F1', 'merchantRefNum' => (string) $tx->id, 'paymentAmount' => '12.499', 'orderAmount' => '12.50',
            'orderStatus' => 'PAID', 'paymentMethod' => 'CARD', 'paymentRefNumber' => 'R1'];
        $fields['messageSignature'] = hash('sha256', 'F1'.$tx->id.'12.50'.'12.50'.'PAID'.'CARD'.'R1'.'SK');

        $this->post(route('webhook', 'fawry'), $fields)->assertOk();
        $this->assertNotSettled($order, $tx); // before: settled (12.499 rounds to 12.50)

        $fields['paymentAmount'] = '12.50';
        $this->post(route('webhook', 'fawry'), $fields)->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_tabby_settles_a_short_payment(): void
    {
        [, $order, $tx] = $this->orderTx('tabby', 12.5, 'KWD', ['secret_key' => 'sk_tabby', 'public_key' => 'pk', 'merchant_code' => 'mc']);
        $tx->update(['payment_id' => 'pay_1']);
        $payment = fn (string $amount) => Http::response(['id' => 'pay_1', 'status' => 'CLOSED', 'amount' => $amount, 'currency' => 'KWD',
            'order' => ['reference_id' => (string) $tx->id]]);
        Http::fake(['*/api/v2/payments/pay_1' => Http::sequence()->pushResponse($payment('12.499'))->pushResponse($payment('12.500'))]);
        $notify = fn () => $this->postJson(route('webhook', 'tabby'), ['id' => 'pay_1', 'order' => ['reference_id' => (string) $tx->id]], ['X-Tabby-Auth' => 'sk_tabby'])->assertOk();

        $notify();
        $this->assertSame(0, (int) $order->fresh()->is_paid); // before: 1
        $notify();
        $this->assertSame(1, (int) $order->fresh()->is_paid);
    }

    private function jwt(array $claims, string $secret): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64((string) json_encode($claims));

        return $head.'.'.$body.'.'.$b64(hash_hmac('sha256', $head.'.'.$body, $secret, true));
    }

    public function test_tamara_settles_a_short_capture(): void
    {
        [, $order, $tx] = $this->orderTx('tamara', 12.5, 'KWD', ['api_token' => 'API', 'notification_token' => 'NOTE']);
        $tx->forceFill(['details' => ['tamara_order_id' => 'TO-1']])->save();
        Http::fake(['*/orders/TO-1/authorise' => Http::response(['status' => 'authorised']),
            '*/orders/TO-1/capture' => Http::response(['capture_id' => 'C1', 'total_amount' => ['amount' => 12.499, 'currency' => 'KWD']])]);
        $token = $this->jwt(['order_reference_id' => (string) $tx->id, 'order_id' => 'TO-1', 'event_type' => 'order_approved', 'exp' => time() + 600], 'NOTE');

        $this->postJson(route('webhook', 'tamara'), [], ['tamaraToken' => $token])->assertOk();
        $this->assertSame(0, (int) $order->fresh()->is_paid); // before: 1
        $this->assertNull(Transaction::find($tx->id)->status);
    }

    public function test_tap_settles_a_short_charge(): void
    {
        [, $order, $tx] = $this->orderTx('tap', 12.5, 'KWD', ['secret_key' => 'sk_test_store']);
        $tx->forceFill(['details' => ['tap_charge_id' => 'chg_1']])->save();
        $charge = fn ($amount) => Http::response(['id' => 'chg_1', 'status' => 'CAPTURED', 'amount' => $amount, 'currency' => 'KWD',
            'live_mode' => false, 'reference' => ['transaction' => (string) $tx->id]]);
        Http::fake(['*/charges/chg_1' => Http::sequence()->pushResponse($charge(12.491))->pushResponse($charge(12.5))]);

        $this->get('/ipn/tap?tap_id=chg_1&txn='.$tx->id);
        $this->assertNotSettled($order, $tx); // before: settled
        $this->get('/ipn/tap?tap_id=chg_1&txn='.$tx->id);
        $this->assertSettledOnce($order, $tx);
    }

    public function test_mollie_settles_a_payment_a_cent_short(): void
    {
        [, $order, $tx] = $this->orderTx('mollie', 12.5, 'EUR', ['api_key' => 'test_fakeMollieStoreKey0000000000000']);
        $tx->update(['payment_id' => 'tr_TEST9']);
        Mollie::fake([GetPaymentRequest::class => MockResponse::ok(['resource' => 'payment', 'id' => 'tr_TEST9', 'status' => 'paid',
            'amount' => ['currency' => 'EUR', 'value' => '12.49'], '_links' => ['checkout' => ['href' => 'https://pay.mollie.test/x', 'type' => 'text/html']]])]);

        $this->post(route('webhook', 'mollie'), ['id' => 'tr_TEST9'])->assertOk();
        $this->assertNotSettled($order, $tx); // before: settled (float |12.49 − 12.5| < 0.01)
    }

    // ------------------------------------------------------------------ sends (OMR 12.345 / BHD 12.500)

    /** @return mixed the amount field of the last request to $needle, null when none was sent */
    private function sentAmount(string $needle, string $path): mixed
    {
        $hit = Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), $needle))->last();

        return $hit === null ? null : data_get($hit[0]->data(), $path);
    }

    private function pay(string $controller, string $gateway, float $amount, string $currency, array $creds): void
    {
        [, , $tx] = $this->orderTx($gateway, $amount, $currency, $creds);
        request()->merge(['email' => 'a@example.com', 'phone' => '123', 'name' => 'A']);
        $controller::pay($tx);
    }

    /** @return array<string, array{0: class-string, 1: string, 2: array<string, string>, 3: string, 4: string}> */
    public static function senders(): array
    {
        return [
            'noon' => [NoonController::class, 'noon', ['business_id' => 'BUS', 'application_id' => 'APP', 'api_key' => 'KEY'], '/order', 'order.amount'],
            'geidea' => [GeideaController::class, 'geidea', ['merchant_public_key' => 'PK', 'api_password' => 'PASS'], '/direct/session', 'amount'],
            'paylink' => [PaylinkController::class, 'paylink', ['api_id' => 'ID', 'secret_key' => 'SECRET'], '/api/addInvoice', 'amount'],
            'fatora' => [FatoraController::class, 'fatora', ['api_key' => 'KEY'], '/payments/checkout', 'amount'],
            'paymob' => [PaymobController::class, 'paymob', ['secret_key' => 'S', 'public_key' => 'P', 'integration_id' => '5', 'hmac_secret' => 'H'], '/v1/intention/', 'amount'],
        ];
    }

    private function fakeSenders(): void
    {
        Http::fake(['*/api/auth' => Http::response(['id_token' => 'tok']), '*' => Http::response(['message' => 'stop'], 400)]);
    }

    #[DataProvider('senders')]
    public function test_sends(string $controller, string $gateway, array $creds, string $needle, string $path): void
    {
        $this->fakeSenders();
        $this->pay($controller, $gateway, 12.345, 'OMR', $creds);
        $omr = $this->sentAmount($needle, $path);
        $this->pay($controller, $gateway, 12.5, 'BHD', $creds);
        $bhd = $this->sentAmount($needle, $path);
        $this->pay($controller, $gateway, 12.5, 'SAR', $creds);
        $sar = $this->sentAmount($needle, $path);

        // before: noon/paylink/fatora [12.35, 12.5, 12.5], geidea ['12.35', '12.50', '12.50'], paymob [1235, 1250, 1250]
        // Gateway decimals vs docs (Lane C): noon documents 3 decimals for KWD/BHD/OMR (before: [null, 12.5, 12.5]);
        // Fatora charges only QAR/USD — it converts anything else to USD (before: [null, 12.5, 12.5]).
        $expected = [
            'noon' => [12.345, 12.5, 12.5], 'geidea' => [null, '12.50', '12.50'], 'paylink' => [null, 12.5, 12.5],
            'fatora' => [null, null, null], 'paymob' => [null, null, 1250],
        ][$gateway];
        $this->assertSame($expected, [$omr, $bhd, $sar]);
    }

    public function test_the_refusals_name_the_amount(): void
    {
        $this->fakeSenders();
        // before: noon (it now takes OMR at 3 decimals); Paylink still carries 2.
        $this->pay(PaylinkController::class, 'paylink', 12.345, 'OMR', ['api_id' => 'ID', 'secret_key' => 'SECRET']);
        $this->assertSame('This payment method cannot charge 12.345 OMR exactly: it takes at most 2 decimals in OMR. Please choose another payment method.', session('app_alert_message'));

        $this->pay(PaymobController::class, 'paymob', 12.5, 'BHD', ['secret_key' => 'S', 'public_key' => 'P', 'integration_id' => '5', 'hmac_secret' => 'H']);
        $this->assertSame('This payment method cannot charge in BHD. Please choose another payment method.', session('app_alert_message'));

        app()->setLocale('fr');
        $this->pay(GeideaController::class, 'geidea', 12.345, 'OMR', ['merchant_public_key' => 'PK', 'api_password' => 'PASS']);
        $this->assertSame('Ce moyen de paiement ne peut pas encaisser exactement 12.345 OMR : il accepte au plus 2 décimales en OMR. Veuillez choisir un autre moyen de paiement.', session('app_alert_message'));
    }
}
