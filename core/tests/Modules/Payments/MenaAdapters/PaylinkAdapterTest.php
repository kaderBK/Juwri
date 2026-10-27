<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaylinkController;
use Juwri\Modules\Payments\Models\Transaction;

/** P1-23 part 5 — Paylink on the adapter family ({@see MenaAdapterTestCase}). */
class PaylinkAdapterTest extends MenaAdapterTestCase
{
    protected function gateway(): string
    {
        return 'paylink';
    }

    protected function controller(): string
    {
        return PaylinkController::class;
    }

    protected function storeCreds(): array
    {
        return ['api_id' => 'id_store', 'secret_key' => 'secret_store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.paylink_api_id' => 'id_platform', 'settings.paylink_secret_key' => 'secret_platform'];
    }

    /** The auth call names the account; every later call carries that account's token. */
    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        $account = $store ? 'id_store' : 'id_platform';

        return str_ends_with($request->url(), '/api/auth') ? $request['apiId'] === $account : $request->hasHeader('Authorization', 'Bearer tok_'.$account);
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        if (str_ends_with($request->url(), '/api/auth')) {
            return Http::response(['id_token' => 'tok_'.$request['apiId']]);
        }

        return str_ends_with($request->url(), '/api/addInvoice')
            ? Http::response(['url' => 'https://pay.paylink.test/go', 'transactionNo' => 'PL'.$request['orderNumber']])
            : null;
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        $transaction->forceFill(['details' => ['paylink_transaction_no' => 'PL'.$transaction->id]])->save();
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $no = 'PL'.$transaction->id;
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), '/api/getInvoice/'.$no) && $this->sentWith($r, $store)
            ? Http::response(['orderStatus' => 'Paid', 'orderNumber' => (string) $transaction->id, 'transactionNo' => $no, 'amount' => (float) $amount, 'currency' => $currency])
            : null);

        // The callback without the orderNumber hint can only be checked on the platform account.
        return $this->post('/webhook/paylink?transactionNo='.$no.($store ? '&orderNumber='.$transaction->id : ''));
    }

    public function test_a_paid_platform_invoice_naming_a_store_payment_never_settles_it(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), '/api/getInvoice/PLX') && $this->sentWith($r, false)
            ? Http::response(['orderStatus' => 'Paid', 'orderNumber' => (string) $tx->id, 'amount' => 25.0, 'currency' => 'SAR'])
            : null);

        $this->post('/webhook/paylink?transactionNo=PLX')->assertOk(); // no orderNumber: only the platform account is asked

        $this->assertGiftCardNotSettled($store, $tx); // before (orders only were refused): a store payment settled
    }
}
