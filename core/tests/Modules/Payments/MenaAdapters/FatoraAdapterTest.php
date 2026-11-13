<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\FatoraController;
use Juwri\Modules\Payments\Models\Transaction;

/** P1-23 part 6 — Fatora on the adapter family ({@see MenaAdapterTestCase}); QAR (it charges QAR/USD only). */
class FatoraAdapterTest extends MenaAdapterTestCase
{
    protected function gateway(): string
    {
        return 'fatora';
    }

    protected function controller(): string
    {
        return FatoraController::class;
    }

    protected function currency(): string
    {
        return 'QAR';
    }

    protected function otherCurrency(): string
    {
        return 'USD';
    }

    protected function storeCreds(): array
    {
        return ['api_key' => 'fk_store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.fatora_api_key' => 'fk_platform'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        return $request->hasHeader('api_key', $store ? 'fk_store' : 'fk_platform');
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        return str_ends_with($request->url(), '/payments/checkout')
            ? Http::response(['result' => ['checkout_url' => 'https://checkout.fatora.test/'.$request['order_id']]])
            : null;
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        // The old controller stored nothing: our id rides as order_id and comes back on the return / webhook.
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), '/payments/verify') && $this->sentWith($r, $store)
            && (string) $r['order_id'] === (string) $transaction->id
            ? Http::response(['result' => ['status' => 'SUCCESS', 'amount' => (float) $amount, 'currency' => $currency]])
            : null);

        return $this->post('/webhook/fatora', ['order_id' => (string) $transaction->id]);
    }

    public function test_the_store_customer_returns_to_the_store_once_verified(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $this->pay($tx);
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), '/payments/verify') && $this->sentWith($r, true)
            ? Http::response(['result' => ['status' => 'SUCCESS', 'amount' => 25.0, 'currency' => 'QAR']])
            : null);

        $this->get('/ipn/fatora?order_id='.$tx->id)->assertRedirect(route('publicView', $store->slug));

        $this->assertGiftCardSettledOnce($store, $tx);
    }
}
