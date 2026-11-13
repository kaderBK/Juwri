<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\QrpayController;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P1-23 part 6 — QRPay (Syria, SYP) on the adapter family ({@see MenaAdapterTestCase}). Each account's client id /
 * secret id buys its own access token; every create/status call carries that token.
 */
class QrpayAdapterTest extends MenaAdapterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), '/authentication/token')
            ? Http::response(['access_token' => match ([$r['client_id'], $r['secret_id']]) {
                ['C_store', 'S_store'] => 'AT-store',
                ['C_platform', 'S_platform'] => 'AT-platform',
                default => 'AT-unknown',
            }, 'expire_time' => 600])
            : null);
    }

    protected function gateway(): string
    {
        return 'qrpay';
    }

    protected function controller(): string
    {
        return QrpayController::class;
    }

    protected function currency(): string
    {
        return 'SYP';
    }

    protected function otherCurrency(): string
    {
        return 'USD';
    }

    protected function storeCreds(): array
    {
        return ['client_id' => 'C_store', 'secret_id' => 'S_store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.qrpay_client_id' => 'C_platform', 'settings.qrpay_secret_id' => 'S_platform'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        if (str_ends_with($request->url(), '/authentication/token')) {
            return $request['client_id'] === ($store ? 'C_store' : 'C_platform');
        }

        return $request->hasHeader('Authorization', 'Bearer '.($store ? 'AT-store' : 'AT-platform'));
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        return str_ends_with($request->url(), '/payment/create')
            ? Http::response(['token' => 'pt', 'payment_url' => 'https://pay.qrpay.test/'.$request['custom']])
            : null;
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        // The old controller stored nothing: our id rides in `custom` and comes back on the callback.
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), '/payment/status') && $this->sentWith($r, $store)
            && (string) $r['custom'] === (string) $transaction->id
            ? Http::response(['data' => ['custom' => (string) $transaction->id, 'amount' => $amount, 'currency' => $currency], 'message' => ['code' => 200, 'success' => true]])
            : null);

        return $this->post('/webhook/qrpay', ['custom' => (string) $transaction->id]);
    }

    public function test_a_store_customer_cancelling_goes_back_to_the_store(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);

        $this->pay($tx);

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/payment/create') && $r['cancel_url'] === route('publicView', $store->slug));
    }
}
