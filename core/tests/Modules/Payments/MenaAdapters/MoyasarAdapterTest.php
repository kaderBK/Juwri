<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\MoyasarController;
use Juwri\Modules\Payments\Models\Transaction;

/** Phase 4 item 18 — Moyasar on the adapter family ({@see MenaAdapterTestCase}): store keys for store payments. */
class MoyasarAdapterTest extends MenaAdapterTestCase
{
    protected function gateway(): string
    {
        return 'moyasar';
    }

    protected function controller(): string
    {
        return MoyasarController::class;
    }

    protected function storeCreds(): array
    {
        return ['secret_key' => 'sk_test_store', 'webhook_secret' => 'store-token'];
    }

    protected function platformCreds(): array
    {
        return ['settings.moyasar_secret_key' => 'sk_test_platform', 'settings.moyasar_webhook_secret' => 'platform-token'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        return $request->hasHeader('Authorization', 'Basic '.base64_encode(($store ? 'sk_test_store' : 'sk_test_platform').':'));
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/v1/invoices')) {
            return null;
        }

        return Http::response(['id' => 'inv_'.$request['metadata']['transaction_id'], 'status' => 'initiated', 'url' => 'https://checkout.moyasar.com/invoices/x']);
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        // Moyasar had no older controller: the state initiate() leaves
        $transaction->forceFill(['payment_id' => 'inv_'.$transaction->id, 'details' => ['moyasar_invoice_id' => 'inv_'.$transaction->id]])->save();
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $invoice = 'inv_'.$transaction->id;
        if (data_get($transaction->details, 'moyasar_invoice_id') === null) {
            $this->startedByOldController($transaction);
        }
        $minor = (int) round(((float) $amount) * 100);
        $this->answer(fn (ClientRequest $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/invoices/'.$invoice) && $this->sentWith($r, $store)
            ? Http::response(['id' => $invoice, 'status' => 'paid', 'amount' => $minor, 'currency' => $currency, 'metadata' => ['transaction_id' => (string) $transaction->id],
                'payments' => [['id' => 'pay_'.$transaction->id, 'status' => 'paid', 'amount' => $minor, 'currency' => $currency]]])
            : null);

        return $this->get('/ipn/moyasar?txn='.$transaction->id);
    }

    /** Moyasar refunds through its API, on the account the payment was made to; automatic refunds stay unregistered. */
    public function test_a_refund_is_never_sent_to_another_account(): void
    {
        [, , $tx] = $this->makeOrderTransaction('moyasar', 100.0, $this->storeCreds(), 'SAR');
        $tx->forceFill(['status' => Transaction::STATUS_CONSUMED, 'transaction_gatway' => 'moyasar', 'payment_id' => 'pay_'.$tx->id])->save();
        $this->answer(fn (ClientRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/payments/pay_'.$tx->id.'/refund')
            ? Http::response(['id' => 'pay_'.$tx->id, 'status' => 'refunded', 'amount' => 10000, 'refunded' => 1000]) : null);

        $this->assertTrue($this->adapter()->supportsRefund());
        $this->assertSame(['ok' => true, 'reference' => 'pay_'.$tx->id, 'message' => null], $this->adapter()->refund($tx->fresh(), 10.0));
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/refund') && $this->sentWith($r, true) && $r['amount'] === 1000);
        Http::assertNotSent(fn (ClientRequest $r) => $this->sentWith($r, false));
    }
}
