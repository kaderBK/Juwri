<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\GeideaController;
use Juwri\Modules\Payments\Models\Transaction;

/** P1-23 part 5 — Geidea on the adapter family ({@see MenaAdapterTestCase}). */
class GeideaAdapterTest extends MenaAdapterTestCase
{
    protected function gateway(): string
    {
        return 'geidea';
    }

    protected function controller(): string
    {
        return GeideaController::class;
    }

    protected function storeCreds(): array
    {
        return ['merchant_public_key' => 'pk_store', 'api_password' => 'pass_store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.geidea_merchant_public_key' => 'pk_platform', 'settings.geidea_api_password' => 'pass_platform'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        return $request->hasHeader('Authorization', 'Basic '.base64_encode($store ? 'pk_store:pass_store' : 'pk_platform:pass_platform'));
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        return str_contains($request->url(), '/payment-intent/api/v2/direct/session')
            ? Http::response(['session' => ['id' => 's1', 'redirectUrl' => 'https://checkout.geidea.test/s1']])
            : null;
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        // The old controller stored nothing: the callback names our transaction (merchantReferenceId).
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        [$pk, $pass] = $store ? ['pk_store', 'pass_store'] : ['pk_platform', 'pass_platform'];
        $ref = (string) $transaction->id;
        $orderId = 'GEI-'.$ref;
        $ts = '2026-10-04T10:00:00+00:00';

        return $this->post('/webhook/geidea', [
            'merchantReferenceId' => $ref, 'orderId' => $orderId, 'orderAmount' => $amount, 'orderCurrency' => $currency,
            'status' => 'Success', 'timestamp' => $ts,
            'signature' => base64_encode(hash_hmac('sha256', $pk.$amount.$currency.$orderId.'Success'.$ref.$ts, $pass, true)),
        ]);
    }

    public function test_a_platform_signed_callback_is_refused_for_a_store_payment(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);

        $this->confirm($tx, '25.00', 'SAR', false)->assertStatus(401);
        $this->assertGiftCardNotSettled($store, $tx);
    }
}
