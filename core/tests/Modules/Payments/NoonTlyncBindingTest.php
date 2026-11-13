<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\Order;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Tlync\TlyncClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06d — noon: the fetched order must name the transaction whose credentials fetched it; T-Lync: a receipt settles only on an explicit paid status and the right amount.
 * A forged or mismatched notice must not settle.
 */
class NoonTlyncBindingTest extends TestCase
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

    // -------------------------------------------------------------- Noon

    private const NOON = ['business_id' => 'BUS', 'application_id' => 'APP', 'api_key' => 'KEY', 'webhook_secret' => 'noon-secret'];

    private function noonOrder(string $noonId, string $reference, float $amount = 100.0): array
    {
        return ['api-test.noonpayments.com/payment/v1/order/*' => Http::response(['resultCode' => 0, 'result' => ['order' => [
            'id' => $noonId, 'status' => 'CAPTURED', 'reference' => $reference, 'amount' => $amount, 'currency' => 'SAR',
        ]]], 200)];
    }

    public function test_noon_store_owners_own_order_cannot_settle_another_transaction(): void
    {
        config(['settings.noon_business_id' => 'PB', 'settings.noon_application_id' => 'PA', 'settings.noon_api_key' => 'PK', 'settings.noon_sandbox' => true]);
        [, $ownOrder, $ownTx] = $this->makeOrderTransaction('noon', 100.0, self::NOON);
        [, $victim] = $this->makePlanTransaction(100.0, 'SAR', 'wallet_topup');
        // Order fetched with the store owner's credentials names the victim.
        Http::fake($this->noonOrder('NOON-OWN', (string) $victim->id));

        $this->get('/ipn/noon?orderId=NOON-OWN&reference='.$ownTx->id);

        $this->assertNull($victim->fresh()->status);
        $this->assertNotSettled($ownOrder, $ownTx);
    }

    public function test_noon_order_id_must_be_the_one_initiated_for_the_transaction(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, self::NOON);
        $this->details($tx, ['noon_order_id' => 'NOON-1']);
        Http::fake($this->noonOrder('NOON-1', (string) $tx->id));

        $this->get('/ipn/noon?orderId=NOON-2&reference='.$tx->id);
        $this->assertNotSettled($order, $tx);
        Http::assertNothingSent();

        $this->get('/ipn/noon?orderId=NOON-1&reference='.$tx->id);
        $this->assertSettledOnce($order, $tx);
    }

    public function test_noon_pay_records_the_noon_order_id(): void
    {
        [, , $tx] = $this->makeOrderTransaction('noon', 100.0, self::NOON);
        Http::fake(['api-test.noonpayments.com/payment/v1/order' => Http::response(['resultCode' => 0, 'result' => [
            'order' => ['id' => 'NOON-77', 'status' => 'INITIATED'],
            'checkoutData' => ['postUrl' => 'https://checkout.noonpayments.com/go/77'],
        ]], 200)]);

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'noon'])->assertRedirect('https://checkout.noonpayments.com/go/77');

        $this->assertSame('NOON-77', $tx->fresh()->details->noon_order_id);
    }

    // ------------------------------------------------------------- Tlync

    public function test_tlync_receipt_counts_as_paid_only_with_an_explicit_paid_status(): void
    {
        $this->assertFalse(TlyncClient::receiptIsPaid(['reference' => 'R']));
        $this->assertFalse(TlyncClient::receiptIsPaid(['reference' => 'R', 'payment_status' => 'failed']));
        $this->assertFalse(TlyncClient::receiptIsPaid(['result' => 'success', 'payment_status' => 'pending']));
        $this->assertFalse(TlyncClient::receiptIsPaid(['notes_to_shop' => ['payment_status' => 'unpaid']]));
        $this->assertFalse(TlyncClient::receiptIsPaid(['result' => 'incomplete']));
        $this->assertFalse(TlyncClient::receiptIsPaid(['result' => 'failed', 'message' => 'not found']));
        $this->assertFalse(TlyncClient::receiptIsPaid([]));
        $this->assertFalse(TlyncClient::receiptIsPaid('success'));
        // WI-17b: only the documented completed shape counts — a bare reference or notes_to_shop never do.
        $this->assertFalse(TlyncClient::receiptIsPaid(['result' => 'success', 'reference' => 'R']));
        $this->assertFalse(TlyncClient::receiptIsPaid(['notes_to_shop' => ['payment_status' => 'Paid']]));
        $this->assertTrue(TlyncClient::receiptIsPaid(['result' => 'success', 'data' => ['amount' => '1.000', 'currency' => 'LYD', 'reference' => 'R']]));
    }

    public function test_tdsp_failed_receipt_or_wrong_receipt_amount_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tdsp', 25.0, ['store_id' => 'store1', 'token' => 'tok'], 'LYD');

        Http::fake(['uat-api.tlync.ly/api/v3/receipt/transaction' => Http::sequence()
            ->push(['reference' => 'R', 'payment_status' => 'failed'], 200)
            ->push(['result' => 'success', 'data' => ['reference' => 'R', 'amount' => '5.000', 'currency' => 'LYD']], 200)
            ->push(['result' => 'success', 'data' => ['reference' => 'R', 'amount' => '25.000', 'currency' => 'LYD']], 200)]);

        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id, 'amount' => '25.000'])->assertOk();
        $this->assertNotSettled($order, $tx);

        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id, 'amount' => '25.000'])->assertOk();
        $this->assertNotSettled($order, $tx);

        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id, 'amount' => '25.000'])->assertOk();
        $this->assertSettledOnce($order, $tx);
    }
}
