<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TapController;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Refunds\GatewayRefundService;

/** P1-23 part 5 — Tap on the adapter family ({@see MenaAdapterTestCase}). */
class TapAdapterTest extends MenaAdapterTestCase
{
    protected function gateway(): string
    {
        return 'tap';
    }

    protected function controller(): string
    {
        return TapController::class;
    }

    protected function storeCreds(): array
    {
        return ['secret_key' => 'sk_test_store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.tap_secret_key' => 'sk_test_platform'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        return $request->hasHeader('Authorization', 'Bearer '.($store ? 'sk_test_store' : 'sk_test_platform'));
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/v2/charges')) {
            return null;
        }

        return Http::response(['id' => 'chg_'.$request['reference']['transaction'], 'transaction' => ['url' => 'https://tap.test/pay']]);
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        $transaction->forceFill(['details' => ['tap_charge_id' => 'chg_'.$transaction->id]])->save();
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $charge = 'chg_'.$transaction->id;
        $this->answer(fn (ClientRequest $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/charges/'.$charge) && $this->sentWith($r, $store)
            ? Http::response(['id' => $charge, 'status' => 'CAPTURED', 'amount' => (float) $amount, 'currency' => $currency, 'live_mode' => false, 'reference' => ['transaction' => (string) $transaction->id]])
            : null);

        return $this->get('/ipn/tap?tap_id='.$charge.'&txn='.$transaction->id);
    }

    public function test_the_store_customer_returns_to_the_store(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $this->pay($tx);

        $this->confirm($tx->fresh(), '25.00', 'SAR', true)->assertRedirect(route('publicView', $store->slug));
    }

    /**
     * Phase 4 item 18: Tap now refunds through its API (POST /v2/refunds) — always on the account the payment was made
     * to (the store's key here), never the platform's. Automatic store refunds stay off until the owner registers Tap in
     * config refunds.adapters (owner decision 2026-09-27): the refund rail still records a tracked manual refund.
     */
    public function test_a_refund_is_never_sent_to_another_account(): void
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('tap', 100.0, $this->storeCreds(), 'SAR');
        $tx->forceFill(['status' => Transaction::STATUS_CONSUMED, 'transaction_gatway' => 'tap', 'payment_id' => 'chg_'.$tx->id])->save();
        Order::whereKey($order->id)->update(['payment_gateway' => 'tap', 'is_paid' => 1]);
        $this->answer(fn (ClientRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v2/refunds')
            ? Http::response(['id' => 're_1', 'status' => 'PENDING']) : null);

        $this->assertTrue($this->adapter()->supportsRefund());
        $this->assertTrue($this->adapter()->refund($tx->fresh(), 10.0)['ok']);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/v2/refunds') && $this->sentWith($r, true));
        Http::assertNotSent(fn (ClientRequest $r) => $this->sentWith($r, false));

        StoreOption::updateStoreOption($store->id, 'auto_refunds_enabled', '1');
        $result = GatewayRefundService::refund($order->fresh(), 5.0);
        $this->assertFalse($result['automatic'], 'not registered for automatic refunds: a tracked manual refund');
    }
}
