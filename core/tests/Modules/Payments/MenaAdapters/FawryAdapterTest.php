<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\FawryController;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\FawryAdapter;

/**
 * P1-23 part 6 — Fawry on the adapter family ({@see MenaAdapterTestCase}). Fawry signs instead of authenticating: the
 * charge carries the account's merchantCode and a signature with its secureKey, and the notification is signed with
 * the same key — "the platform account confirmed it" is a notification signed with the platform's key.
 */
class FawryAdapterTest extends MenaAdapterTestCase
{
    protected function gateway(): string
    {
        return 'fawry';
    }

    protected function controller(): string
    {
        return FawryController::class;
    }

    protected function currency(): string
    {
        return 'EGP';
    }

    protected function storeCreds(): array
    {
        return ['merchant_code' => 'mc_store', 'security_key' => 'sk_store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.fawry_merchant_code' => 'mc_platform', 'settings.fawry_security_key' => 'sk_platform', 'settings.fawry_sandbox' => '1'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        return str_contains($request->url(), '/ECommerceWeb/api/payments/charge')
            && $request['merchantCode'] === ($store ? 'mc_store' : 'mc_platform');
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        if (! str_contains($request->url(), '/ECommerceWeb/api/payments/charge')) {
            return null;
        }
        $key = $request['merchantCode'] === 'mc_store' ? 'sk_store' : 'sk_platform';
        $expected = FawryAdapter::signCheckout($request['merchantCode'], $request['merchantRefNum'], '', route('ipn', 'fawry'), '1', '1', $request['chargeItems'][0]['price'], $key);

        return $request['signature'] === $expected
            ? Http::response(['nextAction' => ['redirectUrl' => 'https://fawry.test/pay']])
            : Http::response(['statusDescription' => 'bad signature'], 400);
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        // The old controller stored nothing: our id rides as merchantRefNum and comes back in the notification.
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $ref = (string) $transaction->id;
        $key = $store ? 'sk_store' : 'sk_platform';
        $fawryRef = 'FAW'.$ref;

        return $this->post('/webhook/fawry', [
            'fawryRefNumber' => $fawryRef, 'merchantRefNum' => $ref, 'paymentAmount' => $amount, 'orderAmount' => $amount,
            'orderStatus' => 'PAID', 'paymentMethod' => 'CARD', 'paymentRefNumber' => 'PRN'.$ref, 'currencyCode' => $currency,
            'messageSignature' => FawryAdapter::signWebhook($fawryRef, $ref, $amount, $amount, 'PAID', 'CARD', 'PRN'.$ref, $key),
        ]);
    }

    public function test_a_platform_signed_notification_for_a_store_payment_is_refused_with_401(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);

        $this->confirm($tx->fresh(), '25.00', 'EGP', false)->assertStatus(401);

        $this->assertGiftCardNotSettled($store, $tx);
    }

    public function test_the_store_customer_returns_to_the_store(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);

        $this->get('/ipn/fawry?merchantRefNumber='.$tx->id)->assertRedirect(route('publicView', $store->slug));
    }

    public function test_cash_at_kiosk_stays_pending_until_paid(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $ref = (string) $tx->id;

        $this->post('/webhook/fawry', [
            'fawryRefNumber' => 'K'.$ref, 'merchantRefNum' => $ref, 'paymentAmount' => '25.00', 'orderAmount' => '25.00',
            'orderStatus' => 'UNPAID', 'paymentMethod' => 'PAYATFAWRY', 'paymentRefNumber' => '',
            'messageSignature' => FawryAdapter::signWebhook('K'.$ref, $ref, '25.00', '25.00', 'UNPAID', 'PAYATFAWRY', '', 'sk_store'),
        ])->assertOk();
        $this->assertGiftCardNotSettled($store, $tx);

        $this->confirm($tx->fresh(), '25.00', 'EGP', true)->assertOk();
        $this->assertGiftCardSettledOnce($store, $tx);
    }
}
