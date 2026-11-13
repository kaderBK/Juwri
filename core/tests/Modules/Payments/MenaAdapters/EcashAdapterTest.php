<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use App\Models\StoreOption;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\EcashController;
use Juwri\Modules\Payments\Models\Transaction;

/** P1-23 part 6 — eCash (Syria, SYP) on the adapter family ({@see MenaAdapterTestCase}). */
class EcashAdapterTest extends MenaAdapterTestCase
{
    protected function gateway(): string
    {
        return 'ecash';
    }

    protected function controller(): string
    {
        return EcashController::class;
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
        return ['merchant_id' => 'M_store', 'terminal_id' => 'T_store', 'api_key' => 'ek_store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.ecash_merchant_id' => 'M_platform', 'settings.ecash_terminal_id' => 'T_platform', 'settings.ecash_api_key' => 'ek_platform'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        return $request->hasHeader('Authorization', 'Bearer '.($store ? 'ek_store' : 'ek_platform'))
            && $request['merchant_id'] === ($store ? 'M_store' : 'M_platform');
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        return str_ends_with($request->url(), '/checkout')
            ? Http::response(['checkout_url' => 'https://pay.ecash.test/'.$request['order_id']])
            : null;
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        // The old controller stored nothing: our id rides as order_id / custom and comes back on the callback.
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), '/status') && $this->sentWith($r, $store)
            && (string) $r['order_id'] === (string) $transaction->id
            ? Http::response(['status' => 'SUCCESS', 'amount' => $amount, 'currency' => $currency])
            : null);

        return $this->post('/webhook/ecash', ['custom' => (string) $transaction->id]);
    }

    public function test_a_store_payment_without_store_keys_is_refused_and_goes_back_to_the_store(): void
    {
        $store = $this->storeWithGateway();
        StoreOption::updateStoreOption($store->id, 'restaurant_ecash_api_key', '');
        $tx = $this->giftCard($store);

        $this->assertSame(route('publicView', $store->slug), $this->pay($tx)->headers->get('Location'));

        Http::assertNothingSent();
    }
}
