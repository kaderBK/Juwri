<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06e — T-Lync (TDSP) charges Libyan dinars only. A transaction in another
 * currency is refused before any call, and a callback for one never settles,
 * even with a genuinely paid receipt for the same number. Fake keys only.
 */
class TlyncCurrencyTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const CREDS = ['store_id' => 'store_fake', 'token' => 'tok_fake'];

    private const RECEIPT = 'uat-api.tlync.ly/api/v3/receipt/transaction';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private function fakeTlync(array $receipt = ['result' => 'success', 'data' => ['reference' => 'R1', 'amount' => '25.000', 'currency' => 'LYD']]): void
    {
        Http::fake([
            'uat-api.tlync.ly/api/v3/payment/initiate' => Http::response(['result' => 'success', 'url' => 'https://pay.tlync.ly/go/x'], 200),
            self::RECEIPT => Http::response($receipt, 200),
        ]);
    }

    private function pay($tx)
    {
        return $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'tdsp', 'email' => 'c@e.com', 'phone' => '+218900000']);
    }

    public function test_pay_refuses_a_non_lyd_transaction_before_any_call(): void
    {
        [, , $tx] = $this->makeOrderTransaction('tdsp', 25.0, self::CREDS, 'USD');
        $this->fakeTlync();

        $res = $this->pay($tx);

        $this->assertNotSame('https://pay.tlync.ly/go/x', $res->headers->get('Location'));
        Http::assertNothingSent();
    }

    public function test_pay_hands_off_an_lyd_transaction(): void
    {
        [, , $tx] = $this->makeOrderTransaction('tdsp', 25.0, self::CREDS, 'lyd');
        $this->fakeTlync();

        $this->pay($tx)->assertRedirect('https://pay.tlync.ly/go/x');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'payment/initiate') && $r['custom_ref'] === (string) $tx->id);
    }

    public function test_callback_for_a_non_lyd_transaction_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tdsp', 25.0, self::CREDS, 'USD');
        $this->fakeTlync(['result' => 'success', 'data' => ['reference' => 'R1', 'amount' => '25.000', 'currency' => 'LYD']]);

        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id, 'amount' => '25.000'])->assertOk()->assertSee('currency mismatch');

        $this->assertNotSettled($order, $tx);
        Http::assertNothingSent();
    }

    public function test_receipt_in_another_currency_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tdsp', 25.0, self::CREDS, 'LYD');
        $this->fakeTlync(['result' => 'success', 'data' => ['reference' => 'R1', 'amount' => '25.000', 'currency' => 'USD']]);
        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id, 'amount' => '25.000'])->assertOk();
        $this->assertNotSettled($order, $tx);
    }

    public function test_lyd_receipt_settles(): void
    {
        [, $order2, $tx2] = $this->makeOrderTransaction('tdsp', 25.0, self::CREDS, 'LYD');
        $this->fakeTlync(['result' => 'success', 'data' => ['reference' => 'R2', 'amount' => '25.000', 'currency' => 'LYD']]);
        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx2->id, 'amount' => '25.000'])->assertOk();
        $this->assertSettledOnce($order2, $tx2);
    }
}
