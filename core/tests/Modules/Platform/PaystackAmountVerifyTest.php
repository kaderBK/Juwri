<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Paystack settles order payments via the Inline widget, where the charged amount rides
 * on a client-side data-amount attribute — a customer can edit it in the browser and pay
 * less than the order total. The ipn() verify must reject any capture whose amount or
 * currency does not match the server-side transaction, not settle on status alone.
 */
class PaystackAmountVerifyTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private array $creds = ['secret_key' => 'sk_test', 'public_key' => 'pk_test'];

    /** Fake the Paystack verify endpoint with a "success" charge for the given minor-unit amount + currency. */
    private function fakeVerify(int $minorAmount, string $currency = 'NGN', string $status = 'success'): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => [
                    'status' => $status,
                    'amount' => $minorAmount,
                    'currency' => $currency,
                    'gateway_response' => 'Successful',
                ],
            ], 200),
        ]);
    }

    public function test_underpayment_does_not_settle_the_order(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paystack', 100.0, $this->creds, 'NGN');

        // Real order is ₦100.00 (10,000 kobo); attacker pays ₦1.00 (100 kobo).
        $this->fakeVerify(100, 'NGN');

        $this->post('/ipn/paystack', ['reference' => $tx->id]);

        $this->assertNotSettled($order, $tx);
    }

    public function test_currency_mismatch_does_not_settle_the_order(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paystack', 100.0, $this->creds, 'NGN');

        // Correct numeric amount but a different (cheaper) currency.
        $this->fakeVerify(10000, 'USD');

        $this->post('/ipn/paystack', ['reference' => $tx->id]);

        $this->assertNotSettled($order, $tx);
    }

    public function test_matching_amount_and_currency_settles_the_order(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paystack', 100.0, $this->creds, 'NGN');

        // ₦100.00 == 10,000 kobo, same currency → legitimate capture settles.
        $this->fakeVerify(10000, 'NGN');

        $this->post('/ipn/paystack', ['reference' => $tx->id]);

        $this->assertSettledOnce($order, $tx);
    }
}
