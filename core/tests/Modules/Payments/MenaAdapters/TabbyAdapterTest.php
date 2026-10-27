<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use App\Models\StoreOption;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Contracts\CustomerGateways;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TabbyController;
use Juwri\Modules\Payments\Models\Transaction;

/** P1-23 part 5 — Tabby on the adapter family ({@see MenaAdapterTestCase}). */
class TabbyAdapterTest extends MenaAdapterTestCase
{
    protected function gateway(): string
    {
        return 'tabby';
    }

    protected function controller(): string
    {
        return TabbyController::class;
    }

    protected function storeCreds(): array
    {
        return ['secret_key' => 'sk_store', 'public_key' => 'pk_store', 'merchant_code' => 'mc_store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.tabby_secret_key' => 'sk_platform', 'settings.tabby_public_key' => 'pk_platform', 'settings.tabby_merchant_code' => 'mc_platform'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        return $request->hasHeader('Authorization', 'Bearer '.($store ? 'sk_store' : 'sk_platform'));
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        return str_ends_with($request->url(), '/api/v2/checkout')
            ? Http::response(['web_url' => 'https://checkout.tabby.test/go', 'payment' => ['id' => 'PAY'.$request['payment']['order']['reference_id']]])
            : null;
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        $transaction->forceFill(['payment_id' => 'PAY'.$transaction->id])->save();
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $pid = 'PAY'.$transaction->id;
        $this->answer(fn (ClientRequest $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/api/v2/payments/'.$pid) && $this->sentWith($r, $store)
            ? Http::response(['id' => $pid, 'status' => 'CLOSED', 'amount' => $amount, 'currency' => $currency, 'order' => ['reference_id' => (string) $transaction->id]])
            : null);

        return $this->withHeader('X-Tabby-Auth', $store ? 'sk_store' : 'sk_platform')
            ->postJson('/webhook/tabby', ['id' => $pid, 'status' => 'closed', 'order' => ['reference_id' => (string) $transaction->id]]);
    }

    public function test_an_authorized_store_payment_is_captured_on_the_store_account(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $this->pay($tx);
        $pid = 'PAY'.$tx->id;
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), '/payments/'.$pid.'/captures') && $this->sentWith($r, true) ? Http::response(['status' => 'CLOSED']) : null);
        $this->answer(fn (ClientRequest $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/api/v2/payments/'.$pid) && $this->sentWith($r, true)
            ? Http::response(['id' => $pid, 'status' => 'AUTHORIZED', 'amount' => '25.00', 'currency' => 'SAR', 'order' => ['reference_id' => (string) $tx->id]])
            : null);

        $this->withHeader('X-Tabby-Auth', 'sk_store')->postJson('/webhook/tabby', ['id' => $pid, 'order' => ['reference_id' => (string) $tx->id]])->assertOk();

        $this->assertGiftCardSettledOnce($store, $tx);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/captures') && $r['amount'] === '25.00' && $this->sentWith($r, true));
    }

    public function test_bnpl_is_offered_to_store_customers_only_with_the_owner_bnpl_switch(): void
    {
        $store = $this->storeWithGateway();
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '0');

        $this->assertNull(app(CustomerGateways::class)->resolve((int) $store->id, 'tabby'));
    }
}
