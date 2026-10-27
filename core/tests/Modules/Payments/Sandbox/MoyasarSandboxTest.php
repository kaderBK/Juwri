<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Sandbox;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\MoyasarController;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * Phase 4 item 18 — Moyasar test mode (docs.moyasar.com: invoices, payments, refund, webhooks): invoice → hosted page /
 * 3-D Secure (`url`) → `payment_paid` webhook (secret_token) or the return → paid; refund; failed card; duplicate,
 * unsigned and wrongly signed events; the sandbox flag. Amounts in halalas; invented test keys and tokens.
 */
class MoyasarSandboxTest extends SandboxTestCase
{
    private const KEY = 'sk_test_store_vector_not_real';

    private const TOKEN = 'webhook-token-test-vector';

    protected function gateway(): string
    {
        return 'moyasar';
    }

    /** @return array{0: Store, 1: Order, 2: Transaction} */
    private function moyasarOrder(): array
    {
        return $this->order(['secret_key' => self::KEY, 'webhook_secret' => self::TOKEN]);
    }

    /** @return array<string, mixed> */
    private function invoice(Transaction $tx, string $status = 'paid', int $amount = 10000): array
    {
        return ['id' => 'inv_T'.$tx->id, 'status' => $status, 'amount' => $amount, 'currency' => 'SAR', 'url' => 'https://checkout.moyasar.com/invoices/inv_T'.$tx->id,
            'metadata' => ['transaction_id' => (string) $tx->id],
            'payments' => $status === 'paid' ? [['id' => 'pay_T'.$tx->id, 'status' => 'paid', 'amount' => $amount, 'currency' => 'SAR']]
                : [['id' => 'pay_F'.$tx->id, 'status' => 'failed', 'amount' => $amount, 'currency' => 'SAR', 'source' => ['message' => 'DECLINED']]]];
    }

    private function event(Transaction $tx, string $type = 'payment_paid', string $token = self::TOKEN, bool $live = false, string $id = 'evt_T1'): string
    {
        return json_encode(['id' => $id, 'type' => $type, 'created_at' => now()->toIso8601String(), 'secret_token' => $token, 'account_name' => 'Test store',
            'live' => $live, 'data' => ['id' => 'pay_T'.$tx->id, 'status' => $type === 'payment_paid' ? 'paid' : 'failed', 'amount' => 10000, 'currency' => 'SAR', 'invoice_id' => 'inv_T'.$tx->id]]);
    }

    private function started(Transaction $tx): void
    {
        $this->fake(['POST /v1/invoices' => fn (ClientRequest $r) => $this->invoice($tx, 'initiated')]);
        $start = MoyasarController::adapter()->initiate($tx->fresh());
        $this->assertSame('https://checkout.moyasar.com/invoices/inv_T'.$tx->id, $start->headers->get('Location'));
    }

    public function test_invoice_redirect_webhook_paid_once_and_a_duplicate_is_a_no_op(): void
    {
        [, $order, $tx] = $this->moyasarOrder();
        $this->started($tx);
        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://api.moyasar.com/v1/invoices' && $r['amount'] === 10000 && $r['currency'] === 'SAR'
            && $r['callback_url'] === route('webhook', 'moyasar') && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::KEY.':')));
        $this->assertSame('inv_T'.$tx->id, $tx->fresh()->payment_id);

        $this->fake(['GET /v1/invoices/inv_T'.$tx->id => $this->invoice($tx)]);
        $this->webhook($this->event($tx))->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSettledOnce($order, $tx);
        $this->assertSame('pay_T'.$tx->id, $tx->fresh()->payment_id, 'the payment id, which the refund needs');

        $this->webhook($this->event($tx))->assertOk()->assertJson(['message' => 'duplicate']);
        $this->assertCreditedOnce($tx);

        // the buyer comes back afterwards: nothing pending, nothing credited again
        $this->get('/ipn/moyasar?txn='.$tx->id)->assertRedirect();
        $this->assertCreditedOnce($tx);
    }

    public function test_the_return_settles_and_a_failed_card_does_not(): void
    {
        [, $order, $tx] = $this->moyasarOrder();
        $this->started($tx);

        $this->fake(['GET /v1/invoices/' => $this->invoice($tx, 'failed')]);
        $this->get('/ipn/moyasar?txn='.$tx->id)->assertRedirect();
        $this->webhook($this->event($tx, 'payment_failed', id: 'evt_F1'))->assertOk()->assertJson(['message' => 'ignored']);
        $this->assertNotSettled($order, $tx);

        $this->fake(['GET /v1/invoices/' => $this->invoice($tx, 'paid', 9999)]); // a short invoice
        $this->get('/ipn/moyasar?txn='.$tx->id);
        $this->assertNotSettled($order, $tx);

        $this->fake(['GET /v1/invoices/' => $this->invoice($tx)]);
        $this->get('/ipn/moyasar?txn='.$tx->id)->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_unsigned_wrongly_signed_and_unknown_events_are_rejected(): void
    {
        [, $order, $tx] = $this->moyasarOrder();
        $this->started($tx);
        $this->fake(['GET /v1/invoices/' => $this->invoice($tx)]);

        $this->webhook(str_replace('"secret_token":"'.self::TOKEN.'",', '', $this->event($tx)))->assertStatus(400);
        $this->webhook($this->event($tx, token: 'guessed'))->assertStatus(400);
        $this->webhook(str_replace('inv_T'.$tx->id, 'inv_unknown', $this->event($tx)))->assertStatus(400);
        $this->webhook('not json')->assertStatus(400);

        $this->assertNotSettled($order, $tx);
        Http::assertNotSent(fn (ClientRequest $r) => $r->method() === 'GET');
    }

    public function test_refund_posts_the_halalas_to_the_payment(): void
    {
        [, $tx] = $this->makePlanTransaction(100.0, 'SAR', 'wallet_topup');
        config(['settings.moyasar_secret_key' => 'sk_test_platform_vector']);
        $tx->update(['payment_id' => 'pay_P1', 'status' => Transaction::STATUS_SUCCESS, 'transaction_gatway' => 'moyasar']);
        $this->fake(['POST /v1/payments/pay_P1/refund' => ['id' => 'pay_P1', 'status' => 'refunded', 'amount' => 10000, 'refunded' => 2550]]);

        $this->assertSame(['ok' => true, 'reference' => 'pay_P1', 'message' => null], MoyasarController::adapter()->refund($tx->fresh(), 25.5));
        Http::assertSent(fn (ClientRequest $r) => $r['amount'] === 2550 && $r->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_platform_vector:')));

        $this->fake(['POST /v1/payments/pay_P1/refund' => ['__status' => 400, 'body' => ['type' => 'invalid_request_error', 'message' => 'Refund amount exceeds']]]);
        $this->assertSame('Refund amount exceeds', MoyasarController::adapter()->refund($tx->fresh(), 10.0)['message']);
    }

    public function test_with_sandboxes_off_a_test_key_pays_nothing_and_a_test_event_is_refused(): void
    {
        config(['payments.sandbox.enabled' => false]);
        [, $order, $tx] = $this->moyasarOrder();
        $this->fake([]);

        MoyasarController::adapter()->initiate($tx->fresh());
        $this->assertNull($tx->fresh()->payment_id);

        // a live store key: an event that says it comes from the test account is still refused
        StoreOption::updateStoreOption($tx->store_id, 'restaurant_moyasar_secret_key', 'sk_live_store_fake');
        $tx->forceFill(['payment_id' => 'inv_T'.$tx->id, 'details' => ['moyasar_invoice_id' => 'inv_T'.$tx->id]])->save();
        $this->webhook($this->event($tx))->assertStatus(400);

        Http::assertNothingSent();
        $this->assertNotSettled($order, $tx);
    }
}
