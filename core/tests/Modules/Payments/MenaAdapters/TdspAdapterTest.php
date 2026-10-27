<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TdspController;
use Juwri\Modules\Payments\Models\Transaction;

/** P1-23 part 6 — TDSP / T-Lync (Libya, LYD) on the adapter family ({@see MenaAdapterTestCase}). */
class TdspAdapterTest extends MenaAdapterTestCase
{
    protected function gateway(): string
    {
        return 'tdsp';
    }

    protected function controller(): string
    {
        return TdspController::class;
    }

    protected function currency(): string
    {
        return 'LYD';
    }

    protected function otherCurrency(): string
    {
        return 'USD';
    }

    protected function storeCreds(): array
    {
        return ['store_id' => 'S_store', 'token' => 'tok_store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.tdsp_store_id' => 'S_platform', 'settings.tdsp_token' => 'tok_platform', 'settings.tdsp_sandbox' => '1'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        $id = $request['id'] ?? $request['store_id'] ?? null;

        return $request->hasHeader('Authorization', 'Bearer '.($store ? 'tok_store' : 'tok_platform'))
            && $id === ($store ? 'S_store' : 'S_platform');
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        return str_ends_with($request->url(), 'payment/initiate')
            ? Http::response(['result' => 'success', 'url' => 'https://pay.tlync.test/'.$request['custom_ref']])
            : null;
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        // The old controller stored nothing: our id rides as custom_ref and comes back on the backend callback.
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), 'receipt/transaction') && $this->sentWith($r, $store)
            && (string) $r['custom_ref'] === (string) $transaction->id
            ? Http::response(['result' => 'success', 'data' => ['amount' => $amount, 'currency' => $currency, 'custom_ref' => (string) $transaction->id, 'reference' => 'R'.$transaction->id]])
            : null);

        return $this->post('/webhook/tdsp', ['custom_ref' => (string) $transaction->id, 'amount' => $amount, 'result' => 'success']);
    }

    public function test_a_receipt_for_another_reference_never_settles(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), 'receipt/transaction')
            ? Http::response(['result' => 'success', 'data' => ['amount' => '25.000', 'currency' => 'LYD', 'custom_ref' => '999999']])
            : null);

        $this->post('/webhook/tdsp', ['custom_ref' => (string) $tx->id])->assertOk();

        $this->assertGiftCardNotSettled($store, $tx);
    }

    public function test_the_store_customer_returns_to_the_store(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);

        $this->get('/ipn/tdsp?custom_ref='.$tx->id)->assertRedirect(route('publicView', $store->slug));
    }
}
