<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\FlutterwaveController;
use Juwri\Modules\Payments\Models\Transaction;

/** P1-23 part 6 — Flutterwave (Africa) on the adapter family ({@see MenaAdapterTestCase}); NGN. */
class FlutterwaveAdapterTest extends MenaAdapterTestCase
{
    protected function gateway(): string
    {
        return 'flutterwave';
    }

    protected function controller(): string
    {
        return FlutterwaveController::class;
    }

    protected function currency(): string
    {
        return 'NGN';
    }

    protected function otherCurrency(): string
    {
        return 'GHS';
    }

    protected function storeCreds(): array
    {
        return ['secret_key' => 'FLWSECK_TEST-store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.flutterwave_secret_key' => 'FLWSECK_TEST-platform', 'settings.flutterwave_secret_hash' => 'flw-hash'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        return $request->hasHeader('Authorization', 'Bearer FLWSECK_TEST-'.($store ? 'store' : 'platform'));
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        return str_ends_with($request->url(), '/v3/payments')
            ? Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/'.$request['tx_ref']]])
            : null;
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        // The old controller stored nothing: our id rides as tx_ref and comes back with Flutterwave's transaction id.
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $flwId = 'flw_'.$transaction->id;
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), '/transactions/'.$flwId.'/verify') && $this->sentWith($r, $store)
            ? Http::response(['status' => 'success', 'data' => ['id' => $flwId, 'status' => 'successful', 'amount' => (float) $amount, 'currency' => $currency, 'tx_ref' => (string) $transaction->id]])
            : null);

        return $this->call('POST', '/webhook/flutterwave', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_VERIF_HASH' => 'flw-hash'],
            (string) json_encode(['event' => 'charge.completed', 'data' => ['id' => $flwId, 'tx_ref' => (string) $transaction->id]]));
    }

    public function test_the_store_customer_returning_settles_and_goes_back_to_the_store(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $this->pay($tx);
        $flwId = 'flw_'.$tx->id;
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), '/transactions/'.$flwId.'/verify') && $this->sentWith($r, true)
            ? Http::response(['status' => 'success', 'data' => ['status' => 'successful', 'amount' => 25.0, 'currency' => 'NGN', 'tx_ref' => (string) $tx->id]])
            : null);

        $this->get('/ipn/flutterwave?status=successful&transaction_id='.$flwId.'&tx_ref='.$tx->id)
            ->assertRedirect(route('publicView', $store->slug));
        $this->get('/ipn/flutterwave?status=successful&transaction_id='.$flwId.'&tx_ref='.$tx->id); // refresh

        $this->assertGiftCardSettledOnce($store, $tx);
    }

    public function test_a_cancelled_store_payment_goes_back_to_the_store(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);

        $this->get('/ipn/flutterwave?status=cancelled&tx_ref='.$tx->id)->assertRedirect(route('publicView', $store->slug));

        $this->assertGiftCardNotSettled($store, $tx);
    }
}
