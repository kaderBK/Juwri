<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\NoonController;
use Juwri\Modules\Payments\Models\Transaction;

/** P1-23 part 5 — noon on the adapter family ({@see MenaAdapterTestCase}). */
class NoonAdapterTest extends MenaAdapterTestCase
{
    private int $event = 0;

    protected function gateway(): string
    {
        return 'noon';
    }

    protected function controller(): string
    {
        return NoonController::class;
    }

    protected function storeCreds(): array
    {
        return ['business_id' => 'bus_store', 'application_id' => 'app_store', 'api_key' => 'key_store', 'webhook_secret' => 'whsec_store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.noon_business_id' => 'bus_platform', 'settings.noon_application_id' => 'app_platform', 'settings.noon_api_key' => 'key_platform', 'settings.noon_webhook_secret' => 'whsec_platform'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        return $request->hasHeader('Authorization', 'Key '.base64_encode($store ? 'bus_store.app_store:key_store' : 'bus_platform.app_platform:key_platform'));
    }

    private function noonOrderId(Transaction $transaction): string
    {
        return (string) (700000000 + (int) $transaction->id);
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        return $request->method() === 'POST' && str_ends_with($request->url(), '/payment/v1/order')
            ? Http::response(['resultCode' => 0, 'result' => ['order' => ['id' => 700000000 + (int) $request['order']['reference']], 'checkoutData' => ['postUrl' => 'https://checkout.noon.test/go']]])
            : null;
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        $transaction->forceFill(['details' => ['noon_order_id' => $this->noonOrderId($transaction)]])->save();
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $orderId = $this->noonOrderId($transaction);
        $this->answer(fn (ClientRequest $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/order/'.$orderId) && $this->sentWith($r, $store)
            ? Http::response(['resultCode' => 0, 'result' => ['order' => ['status' => 'CAPTURED', 'reference' => (string) $transaction->id, 'amount' => (float) $amount, 'currency' => $currency]]])
            : null);
        $event = ['orderId' => $orderId, 'orderStatus' => 'CAPTURED', 'eventId' => 'E'.(++$this->event), 'eventType' => 'Sale', 'timeStamp' => '2026-10-04T10:00:00Z', 'reference' => (string) $transaction->id];
        $signed = implode(',', [$event['orderId'], $event['orderStatus'], $event['eventId'], $event['eventType'], $event['timeStamp']]);
        $event['signature'] = base64_encode(hash_hmac('sha512', $signed, $store ? 'whsec_store' : 'whsec_platform', true));

        return $this->postJson('/webhook/noon', $event);
    }

    public function test_the_store_customer_return_settles_on_the_store_account_and_goes_back_to_the_store(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $this->pay($tx);
        $orderId = $this->noonOrderId($tx);
        $this->answer(fn (ClientRequest $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/order/'.$orderId) && $this->sentWith($r, true)
            ? Http::response(['result' => ['order' => ['status' => 'CAPTURED', 'reference' => (string) $tx->id, 'amount' => 25.0, 'currency' => 'SAR']]])
            : null);

        $this->get('/ipn/noon?orderId='.$orderId.'&reference='.$tx->id)->assertRedirect(route('publicView', $store->slug));

        $this->assertGiftCardSettledOnce($store, $tx);
    }
}
