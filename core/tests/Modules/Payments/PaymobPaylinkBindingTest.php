<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\Order;
use Juwri\Modules\Payments\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06d — Paymob and Paylink: the signed Paymob order id and the Paylink invoice must belong to the transaction being settled.
 * A forged or mismatched notice must not settle.
 */
class PaymobPaylinkBindingTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private function details(Transaction $tx, array $values): void
    {
        $tx->forceFill(['details' => array_merge((array) ($tx->details ?? []), $values)])->save();
    }

    // ------------------------------------------------------------ Paymob

    private const PAYMOB = ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'integration_id' => '9988', 'hmac_secret' => 'HMAC_TENANT'];

    private function paymobObj(string $merchantOrderId, int $paymobOrderId, int $cents = 10000): array
    {
        return [
            'amount_cents' => $cents, 'created_at' => '2026-09-27T10:00:00', 'currency' => 'SAR',
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => 1, 'integration_id' => 9988,
            'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false, 'is_refunded' => false,
            'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => $paymobOrderId, 'merchant_order_id' => $merchantOrderId],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true,
        ];
    }

    private function paymobHmac(array $obj, string $secret): string
    {
        $concat = '';
        foreach (['amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment', 'is_voided', 'order.id', 'owner', 'pending', 'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success'] as $f) {
            $v = data_get($obj, $f);
            $concat .= is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }

        return hash_hmac('sha512', $concat, $secret);
    }

    public function test_paymob_pay_records_the_paymob_order_id(): void
    {
        [, , $tx] = $this->makeOrderTransaction('paymob', 100.0, self::PAYMOB);
        Http::fake(['ksa.paymob.com/v1/intention/' => Http::response(['client_secret' => 'CS', 'intention_order_id' => 777001], 200)]);

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'paymob', 'email' => 'c@e.com', 'phone' => '+966'])
            ->assertRedirect('https://ksa.paymob.com/unifiedcheckout/?publicKey=pk_test&clientSecret=CS');

        $this->assertSame('777001', $tx->fresh()->details->paymob_order_id);
    }

    public function test_paymob_signed_callback_of_another_payment_cannot_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paymob', 100.0, self::PAYMOB);
        $this->details($tx, ['paymob_order_id' => '777001']);

        // A genuinely signed success for Paymob order 999 (another payment of
        // the same amount), with the unsigned merchant_order_id pointed at $tx.
        $obj = $this->paymobObj((string) $tx->id, 999);
        $this->post('/webhook/paymob?hmac='.$this->paymobHmac($obj, 'HMAC_TENANT'), ['obj' => $obj])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_paymob_callback_for_an_unbound_transaction_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paymob', 100.0, self::PAYMOB);

        $obj = $this->paymobObj((string) $tx->id, 777001);
        $this->post('/webhook/paymob?hmac='.$this->paymobHmac($obj, 'HMAC_TENANT'), ['obj' => $obj])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_paymob_callback_for_its_own_order_settles(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paymob', 100.0, self::PAYMOB);
        $this->details($tx, ['paymob_order_id' => '777001']);

        $obj = $this->paymobObj((string) $tx->id, 777001);
        $this->post('/webhook/paymob?hmac='.$this->paymobHmac($obj, 'HMAC_TENANT'), ['obj' => $obj])->assertOk();

        $this->assertSettledOnce($order, $tx);
    }

    // ----------------------------------------------------------- Paylink

    private function paylinkPlatform(): void
    {
        config(['settings.paylink_api_id' => 'PLATFORM_ID', 'settings.paylink_secret_key' => 'PLATFORM_SECRET', 'settings.paylink_sandbox' => true]);
    }

    public function test_paylink_store_owners_own_invoice_cannot_settle_a_platform_transaction(): void
    {
        $this->paylinkPlatform();
        // The attacker's store (its own Paylink account, sandbox) and a pending order there.
        [, $ownOrder, $ownTx] = $this->makeOrderTransaction('paylink', 100.0, ['api_id' => 'OWNER_ID', 'secret_key' => 'OWNER_SECRET']);
        // Someone else's pending platform top-up of the same amount.
        [, $victim] = $this->makePlanTransaction(100.0, 'SAR', 'wallet_topup');

        Http::fake([
            'restpilot.paylink.sa/api/auth' => Http::response(['id_token' => 'T'], 200),
            // The owner's account holds a paid invoice whose orderNumber names the victim.
            'restpilot.paylink.sa/api/getInvoice/*' => Http::response(['orderStatus' => 'Paid', 'orderNumber' => (string) $victim->id, 'amount' => 100.0, 'currency' => 'SAR'], 200),
        ]);

        $this->post('/webhook/paylink', ['transactionNo' => 'INV-OWN', 'orderNumber' => (string) $ownTx->id])->assertOk();

        $this->assertNull($victim->fresh()->status);
        $this->assertNotSettled($ownOrder, $ownTx);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/auth') && $r['apiId'] === 'OWNER_ID');
    }

    public function test_paylink_platform_invoice_cannot_settle_a_store_order(): void
    {
        $this->paylinkPlatform();
        [, $order, $tx] = $this->makeOrderTransaction('paylink', 100.0, ['api_id' => 'OWNER_ID', 'secret_key' => 'OWNER_SECRET']);
        Http::fake([
            'restpilot.paylink.sa/api/auth' => Http::response(['id_token' => 'T'], 200),
            'restpilot.paylink.sa/api/getInvoice/*' => Http::response(['orderStatus' => 'Paid', 'orderNumber' => (string) $tx->id, 'amount' => 100.0, 'currency' => 'SAR'], 200),
        ]);

        $this->post('/webhook/paylink', ['transactionNo' => 'INV-PLATFORM'])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_paylink_invoice_number_must_be_the_one_issued_for_the_transaction(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paylink', 100.0, ['api_id' => 'OWNER_ID', 'secret_key' => 'OWNER_SECRET']);
        $this->details($tx, ['paylink_transaction_no' => 'INV-1']);
        Http::fake([
            'restpilot.paylink.sa/api/auth' => Http::response(['id_token' => 'T'], 200),
            'restpilot.paylink.sa/api/getInvoice/*' => Http::response(['orderStatus' => 'Paid', 'orderNumber' => (string) $tx->id, 'amount' => 100.0, 'currency' => 'SAR'], 200),
        ]);

        $this->post('/webhook/paylink', ['transactionNo' => 'INV-OTHER', 'orderNumber' => (string) $tx->id])->assertOk();
        $this->assertNotSettled($order, $tx);

        $this->post('/webhook/paylink', ['transactionNo' => 'INV-1', 'orderNumber' => (string) $tx->id])->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_paylink_pay_records_the_invoice_number(): void
    {
        [, , $tx] = $this->makeOrderTransaction('paylink', 100.0, ['api_id' => 'OWNER_ID', 'secret_key' => 'OWNER_SECRET']);
        Http::fake([
            'restpilot.paylink.sa/api/auth' => Http::response(['id_token' => 'T'], 200),
            'restpilot.paylink.sa/api/addInvoice' => Http::response(['transactionNo' => 'INV-9', 'url' => 'https://pay.sa/go/9'], 200),
        ]);

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'paylink', 'email' => 'c@e.com', 'phone' => '+966'])
            ->assertRedirect('https://pay.sa/go/9');

        $this->assertSame('INV-9', $tx->fresh()->details->paylink_transaction_no);
    }
}
