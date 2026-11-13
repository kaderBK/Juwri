<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\MidtransController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Midtrans IDR precision (found while fixing PayPal's): Midtrans takes `gross_amount` as an integer — whole rupiah,
 * while ISO gives IDR 2 decimals (GatewayMinorUnits::GATEWAY_ZERO_DECIMAL). A fractional amount is refused before
 * the call instead of being sent as a float Midtrans rejects; refunds the same.
 *
 * `before:` comments record what the same assertion returned before the fix.
 */
class MidtransIdrPrecisionTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        app()->setLocale('en'); // messages are asserted in English (the test app's default locale is not)
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'midtrans', 'payment_title' => 'Midtrans', 'payment_install' => '1']);
        config(['settings.midtrans_server_key' => 'Mid-server-fakePlatform', 'settings.midtrans_client_key' => 'Mid-client-fakePlatform',
            'settings.midtrans_sandbox_mode' => 'live', 'settings.currency_code' => 'IDR']);
    }

    public function test_a_fractional_idr_payment_is_refused_not_rounded(): void
    {
        [, $tx] = $this->makePlanTransaction(15000.5, 'IDR', 'wallet_topup');
        Http::fake(['app.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap-tok'], 201)]);

        $this->assertTrue(MidtransController::pay($tx)->isRedirect());

        Http::assertNothingSent(); // before: gross_amount 15000.5 sent
        $this->assertSame('This payment method only charges whole amounts in IDR, and this payment is 15000.50 IDR. Please choose another payment method.', session('app_alert_message'));
    }

    public function test_a_whole_idr_payment_is_unchanged(): void
    {
        [, $tx] = $this->makePlanTransaction(15000, 'IDR', 'wallet_topup');
        Http::fake(['app.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap-tok'], 201)]);

        MidtransController::pay($tx);

        Http::assertSent(fn (ClientRequest $r) => $r['transaction_details']['gross_amount'] === 15000 && $r['item_details'][0]['price'] === 15000);
    }

    public function test_a_fractional_idr_refund(): void
    {
        [, $tx] = $this->makePlanTransaction(10000.0, 'IDR', 'wallet_topup');
        $tx->update(['status' => Transaction::STATUS_SUCCESS]);
        Http::fake(['api.midtrans.com/v2/*/refund' => fn (ClientRequest $r) => Http::response(['status_code' => '200', 'refund_key' => $r['refund_key']])]);

        $this->assertFalse(MidtransController::adapter()->refund($tx->fresh(), 100.5)['ok']); // before: true
        Http::assertNothingSent(); // before: amount 100.5 sent
        $this->assertTrue(MidtransController::adapter()->refund($tx->fresh(), 100.0)['ok']);
        Http::assertSent(fn (ClientRequest $r) => $r['amount'] === 100);
    }
}
