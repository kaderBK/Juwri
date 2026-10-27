<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\IyzicoController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaddleController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\TerminalAccount;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\FawryAdapter;
use Juwri\Modules\Payments\Services\Gateways\IyzicoAdapter;
use Juwri\Modules\Payments\Services\Terminal\SoftPos\GeideaSoftPos;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P6-81: gateway amounts go through GatewayMinorUnits, never a hard-coded 2 decimals / ×100.
 * Adapter × currency (KWD/BHD 3dp, JPY 0dp, SAR/USD/EGP 2dp), each at the provider's own convention.
 */
class GatewayAmountMatrixTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    /** @return array<string, array{string, float|int, int, string, string}> currency, amount, paddle minor, iyzico major, geidea hmac amount */
    public static function matrix(): array
    {
        return [
            'KWD' => ['KWD', 12.345, 12345, '12.345', '12.35'],
            'BHD' => ['BHD', 1.5, 1500, '1.500', '1.50'],
            'JPY' => ['JPY', 1500, 1500, '1500', '1500'],
            'SAR' => ['SAR', 12.5, 1250, '12.50', '12.50'],
            'USD' => ['USD', 12.34, 1234, '12.34', '12.34'],
            'EGP' => ['EGP', 99.99, 9999, '99.99', '99.99'],
        ];
    }

    private static function invokePrivate(object|string $target, string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod($target, $method);

        return $m->invoke(is_object($target) ? $target : null, ...$args);
    }

    #[DataProvider('matrix')]
    public function test_paddle_minor_units_follow_the_iso_exponent(string $cur, float|int $amount, int $minor, string $major, string $hmacAmount): void
    {
        $tx = new Transaction(['amount' => $amount, 'currency_code' => $cur]);
        $tx->amount = $amount;
        $tx->currency_code = $cur;

        $this->assertSame($minor, self::invokePrivate(PaddleController::class, 'minorUnits', $tx));
    }

    #[DataProvider('matrix')]
    public function test_iyzico_price_is_a_major_string_at_the_currency_decimals(string $cur, float|int $amount, int $minor, string $major, string $hmacAmount): void
    {
        $this->assertSame($major, self::invokePrivate(app(IyzicoAdapter::class), 'price', $amount, $cur));
    }

    #[DataProvider('matrix')]
    public function test_geidea_callback_hmac_is_over_the_amount_at_geidea_decimals(string $cur, float|int $amount, int $minor, string $major, string $hmacAmount): void
    {
        $account = new TerminalAccount;
        $account->credentials = ['public_key' => 'pk', 'api_password' => 'secret'];
        $order = ['orderId' => 'O1', 'amount' => $amount, 'currency' => $cur, 'status' => 'Success', 'merchantReferenceId' => 'R1'];
        $sig = base64_encode(hash_hmac('sha256', 'pk'.$hmacAmount.$cur.'O1SuccessR1T', 'secret', true));
        $request = Request::create('/', 'POST', ['order' => $order, 'signature' => $sig, 'timeStamp' => 'T']);

        $this->assertTrue(app(GeideaSoftPos::class)->verifyCallback($account, $request));
    }

    public function test_fawry_signs_egp_at_two_decimals(): void
    {
        $this->assertSame('99.90', self::invokePrivate(FawryAdapter::class, 'money', 99.9));
        $this->assertSame(hash('sha256', 'MCREF1https://r/I111000.00K'), FawryAdapter::signCheckout('MC', 'REF1', '', 'https://r/', 'I1', '1', 1000, 'K'));
    }

    #[DataProvider('iyzicoCurrencies')]
    public function test_iyzico_pay_and_refund_send_the_amount_at_the_currency_decimals(string $cur, float $amount, string $sent, float $refund, string $refundSent): void
    {
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'iyzico', 'payment_title' => 'Iyzico', 'payment_install' => '1']);
        config(['settings.iyzico_sandbox_mode' => 'live', 'settings.iyzico_api_key' => 'fake-api', 'settings.iyzico_secret_key' => 'fake-secret', 'settings.currency_code' => $cur]);
        [, $tx] = $this->makePlanTransaction($amount, $cur, 'wallet_topup');
        Http::fake([
            'api.iyzipay.com/payment/iyzipos/checkoutform/initialize/auth/ecom' => Http::response(['status' => 'success', 'token' => 't', 'paymentPageUrl' => 'https://pay.example/t']),
            'api.iyzipay.com/v2/payment/refund' => Http::response(['status' => 'success', 'paymentId' => 'PAY9', 'price' => $refund]),
        ]);

        IyzicoController::pay($tx);
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'initialize') && $r['price'] === $sent && $r['paidPrice'] === $sent && $r['currency'] === $cur);

        $tx->update(['status' => Transaction::STATUS_SUCCESS, 'payment_id' => 'PAY9']);
        $this->assertTrue(IyzicoController::adapter()->refund($tx->fresh(), $refund)['ok']);
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'refund') && $r['price'] === $refundSent);
    }

    /** @return array<string, array{string, float, string, float, string}> */
    public static function iyzicoCurrencies(): array
    {
        return [
            'USD' => ['USD', 12.5, '12.50', 2.25, '2.25'],
            'TRY' => ['TRY', 1000.0, '1000.00', 0.5, '0.50'],
        ];
    }
}
