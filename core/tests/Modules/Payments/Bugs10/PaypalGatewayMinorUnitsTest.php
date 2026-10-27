<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Bugs10;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaypalController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Bug batch 10 · 2 — the PayPal adapter sent amounts at the DISPLAY decimals (currency_decimals: IQD 2) and settled a
 * capture within ± 0.01 of the amount (a KWD capture 0.009 short settled). It now formats like every other gateway —
 * GatewayMinorUnits from the stored minor units — and settles only on the exact amount.
 *
 * `before:` comments record what the same assertion returned before the fix.
 */
class PaypalGatewayMinorUnitsTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        PaymentGateway::create(['payment_folder' => 'paypal', 'payment_title' => 'PayPal', 'payment_install' => '1']);
        config(['settings.paypal_api_client_id' => 'fake-platform-client', 'settings.paypal_api_secret' => 'fake-platform-secret',
            'settings.paypal_sandbox_mode' => 'Yes', 'settings.paypal_payment_mode' => 'one_time', 'settings.currency_code' => 'USD',
            'settings.site_title' => 'juwri']);
    }

    /** @param array<string, mixed> $routes */
    private function fakePaypal(array $routes): void
    {
        Http::fake(function (ClientRequest $request) use ($routes) {
            if (str_ends_with($request->url(), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 300]);
            }
            foreach ($routes as $suffix => $answer) {
                [$method, $path] = explode(' ', $suffix, 2);
                if ($request->method() === $method && str_contains($request->url(), $path)) {
                    return Http::response($answer);
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

    private function orderAmountSent(float $amount, string $currency): array
    {
        [, , $tx] = $this->orderTx($amount, $currency);
        $this->fakePaypal(['POST /v2/checkout/orders' => ['id' => 'ORDER-1', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.test/x']]]]);
        PaypalController::pay($tx);
        $sent = null;
        Http::assertSent(function (ClientRequest $r) use (&$sent) {
            if (str_ends_with($r->url(), '/v2/checkout/orders')) {
                $sent = $r['purchase_units'][0]['amount'];

                return true;
            }

            return false;
        });

        return $sent;
    }

    public function test_order_amount_uses_the_gateway_minor_unit(): void
    {
        $this->assertSame(['currency_code' => 'IQD', 'value' => '13.833'], $this->orderAmountSent(13.833, 'IQD')); // before: '13.83'
    }

    public function test_usd_kwd_jpy_are_unchanged(): void
    {
        $this->assertSame('12.50', $this->orderAmountSent(12.5, 'USD')['value']);
        $this->assertSame('12.500', $this->orderAmountSent(12.5, 'KWD')['value']);
        $this->assertSame('1200', $this->orderAmountSent(1200, 'JPY')['value']);
    }

    public function test_a_capture_short_by_less_than_a_cent_does_not_settle(): void
    {
        [, $order, $tx] = $this->orderTx(12.5, 'KWD');
        $tx->update(['payment_id' => 'ORDER-K']);
        $this->fakePaypal(['POST /v2/checkout/orders/ORDER-K/capture' => ['id' => 'ORDER-K', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-K', 'amount' => ['value' => '12.491', 'currency_code' => 'KWD']]]]]]]]);

        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORDER-K')->assertRedirect(); // before: settled (|12.491 − 12.5| < 0.01)
        $this->assertNotSettled($order, $tx);
    }

    public function test_the_exact_capture_settles(): void
    {
        [, $order, $tx] = $this->orderTx(12.5, 'KWD');
        $tx->update(['payment_id' => 'ORDER-E']);
        $this->fakePaypal(['POST /v2/checkout/orders/ORDER-E/capture' => ['id' => 'ORDER-E', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-E', 'amount' => ['value' => '12.500', 'currency_code' => 'KWD']]]]]]]]);

        $this->get(route('ipn', 'paypal').'?pay_mode=one_time&token=ORDER-E')->assertOk();
        $this->assertSettledOnce($order, $tx);
    }
}
