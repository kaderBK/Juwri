<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TamaraController;
use Juwri\Modules\Payments\Models\Transaction;

/** P1-23 part 5 — Tamara on the adapter family ({@see MenaAdapterTestCase}). */
class TamaraAdapterTest extends MenaAdapterTestCase
{
    protected function gateway(): string
    {
        return 'tamara';
    }

    protected function controller(): string
    {
        return TamaraController::class;
    }

    protected function storeCreds(): array
    {
        return ['api_token' => 'api_store', 'notification_token' => 'notify_store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.tamara_api_token' => 'api_platform', 'settings.tamara_notification_token' => 'notify_platform'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        return $request->hasHeader('Authorization', 'Bearer '.($store ? 'api_store' : 'api_platform'));
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        return str_ends_with($request->url(), '/checkout')
            ? Http::response(['checkout_url' => 'https://checkout.tamara.test/go', 'order_id' => 'TO'.$request['order_reference_id']])
            : null;
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        $transaction->forceFill(['details' => ['tamara_order_id' => 'TO'.$transaction->id]])->save();
    }

    private function jwt(array $claims, string $secret): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64((string) json_encode($claims));

        return $head.'.'.$body.'.'.$b64(hash_hmac('sha256', $head.'.'.$body, $secret, true));
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $orderId = 'TO'.$transaction->id;
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), '/orders/'.$orderId.'/authorise') && $this->sentWith($r, $store) ? Http::response(['status' => 'authorised']) : null);
        $this->answer(fn (ClientRequest $r) => str_ends_with($r->url(), '/orders/'.$orderId.'/capture') && $this->sentWith($r, $store)
            ? Http::response(['capture_id' => 'c1', 'status' => 'fully_captured', 'captured_amount' => ['amount' => (float) $amount, 'currency' => $currency]])
            : null);
        $token = $this->jwt(['order_reference_id' => (string) $transaction->id, 'order_id' => $orderId, 'event_type' => 'order_approved', 'exp' => time() + 600], $store ? 'notify_store' : 'notify_platform');

        return $this->withHeader('tamaraToken', $token)->postJson('/webhook/tamara', ['order_reference_id' => (string) $transaction->id, 'order_id' => $orderId, 'event_type' => 'order_approved']);
    }

    public function test_the_capture_names_the_store_as_the_shipping_company(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $this->pay($tx);

        $this->confirm($tx->fresh(), '25.00', 'SAR', true)->assertOk();

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/capture') && $r['shipping_info']['shipping_company'] === 'R' && $r['total_amount']['currency'] === 'SAR');
    }
}
