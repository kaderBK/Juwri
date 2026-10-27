<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayCurrencies;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Symfony\Component\HttpFoundation\Response;

/**
 * G7 Fatora (fatora.io) — Qatar hosted checkout (QAR / USD only, {@see GatewayCurrencies::FIXED}). P1-23 part 6: moved
 * from the hand-written FatoraController into the adapter family, same wire protocol — the routes, the success/failure
 * URL `ipn/fatora`, the dashboard webhook `webhook/fatora` and our id as `order_id` are unchanged, so a checkout opened
 * by the old controller still settles here.
 *
 * initiate: POST /v1/payments/checkout (header `api_key`) → the hosted checkout_url.
 * Return and webhook: their params (order_id, transaction_id, response_code) are UNSIGNED and Fatora publishes no
 * webhook signature — they only name OUR pending transaction. Its account's key — the STORE's for orders and store
 * payments (gift cards, top-ups, deposits; {@see Settlement::usesStoreGateway()}), the platform's for plans/add-ons; no
 * key = refused — asks POST /v1/payments/verify, which must say SUCCESS for the exact amount and currency; settles once
 * (WebhookReceipts + claim). The return is then display-only (store payments back to their store).
 *
 * ASSUMPTION (docs weak — fatora.io/api + Stoplight): verify = POST {base}/payments/verify {order_id}; success from
 * result.status / status, amount from result.amount / amount, checkout URL from result.checkout_url / checkout_url.
 * No refund API wired (the old controller had none): tracked manual refund.
 */
class FatoraAdapter implements GatewayAdapter
{
    private const BASE = 'https://api.fatora.io/v1';

    public function key(): string
    {
        return 'fatora';
    }

    public function initiate(Transaction $transaction): Response
    {
        if (($invalid = GatewayBuyer::requireOrderContact($transaction)) !== null) {
            return $invalid;
        }
        $cancelUrl = Settlement::cancelUrl($transaction);
        $buyer = GatewayBuyer::of($transaction);

        $apiKey = $this->apiKey($transaction);
        if ($apiKey === '') {
            app_alert_error(___('This payment method is not configured.'));

            return redirect($cancelUrl);
        }
        // The amount at Fatora's decimals in QAR/USD, or a refusal — never rounded.
        $currency = $this->currency($transaction);
        [$amount, $refusal] = GatewayCurrencies::amountOrRefusal('fatora', $transaction, $currency);
        if ($amount === null) {
            app_alert_error((string) $refusal);

            return redirect($cancelUrl);
        }

        try {
            $resp = Http::withHeaders(['api_key' => $apiKey])->acceptJson()->post(self::BASE.'/payments/checkout', [
                'amount' => (float) $amount, // a JSON number, from the exact decimal string above
                'currency' => $currency,
                'order_id' => (string) $transaction->id,
                'client' => ['name' => $buyer['name'], 'phone' => $buyer['phone'], 'email' => $buyer['email']],
                'success_url' => route('ipn', 'fatora'),
                'failure_url' => route('ipn', 'fatora'),
                'note' => $transaction->product_name ?: 'Order',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[fatora] checkout failed: '.$e->getMessage());
            app_alert_error(___('The payment service is unavailable right now. Please try again.'));

            return redirect($cancelUrl);
        }

        $url = $resp->json('result.checkout_url') ?: $resp->json('checkout_url');
        if ($resp->successful() && is_string($url) && $url !== '') {
            return redirect()->away($url);
        }

        Log::warning('[fatora] checkout rejected: '.$resp->status());
        app_alert_error(___('Could not start the payment. Please try again.'));

        return redirect($cancelUrl);
    }

    /** Customer return (success/failure URL): verify, then display-only. */
    public function handleReturn(Request $request): Response
    {
        $orderId = $this->orderId($request);
        $this->verifyAndSettle($orderId);

        return Settlement::confirmingForBuyer($orderId !== null ? Transaction::find($orderId) : null);
    }

    /** Dashboard webhook — verify, then acknowledge. Never settles from the body. */
    public function handleWebhook(Request $request): Response
    {
        $this->verifyAndSettle($this->orderId($request));

        return response('ok', 200);
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        return Settlement::noAutomaticRefund();
    }

    /**
     * Ask Fatora (with the key of the account the transaction is paid into) and settle once. True when this call settled.
     * The verify response is the only truth — nothing in the request is trusted.
     */
    public function verifyAndSettle(?string $orderId): bool
    {
        $transaction = $orderId !== null
            ? Transaction::where([['id', $orderId], ['status', null], ['payment_id', null]])->first()
            : null;
        if ($transaction === null) {
            return false; // unknown or already settled
        }
        $apiKey = $this->apiKey($transaction);
        if ($apiKey === '') {
            return false;
        }

        try {
            $resp = Http::withHeaders(['api_key' => $apiKey])->acceptJson()->post(self::BASE.'/payments/verify', ['order_id' => (string) $transaction->id]);
        } catch (\Throwable $e) {
            Log::warning('[fatora] verify failed: '.$e->getMessage());

            return false;
        }
        if (! $resp->successful() || strtoupper((string) ($resp->json('result.status') ?? $resp->json('status'))) !== 'SUCCESS') {
            return false;
        }

        $paid = $resp->json('result.amount') ?? $resp->json('amount');
        $paid = is_int($paid) || is_float($paid) || is_string($paid) ? $paid : '';
        $paidCurrency = $resp->json('result.currency') ?? $resp->json('currency');
        // Exact at Fatora's decimals, in our currency (a reported currency must agree) — logged on mismatch.
        if (! Settlement::amountMatches($transaction, $paid, is_scalar($paidCurrency) ? (string) $paidCurrency : null, 'fatora', $this->currency($transaction))) {
            return false;
        }

        // J25-7: no event id — "fatora confirmed this payment paid"; a repeated or concurrent confirmation is a no-op.
        return WebhookReceipts::once('fatora', WebhookReceipts::key($transaction->id, 'paid'), fn (): bool => Settlement::settle($transaction, 'fatora', null, claim: true), false);
    }

    /* ------------------------------------------------------------ internals */

    private function orderId(Request $request): ?string
    {
        $id = $request->input('order_id') ?: $request->input('order_number');

        return is_scalar($id) && ctype_digit((string) $id) ? (string) $id : null;
    }

    private function currency(Transaction $transaction): string
    {
        return strtoupper((string) ($transaction->currency_code ?: 'QAR'));
    }

    /** The api_key of the account this transaction is paid into: the store's (orders, store payments) or the platform's. */
    private function apiKey(Transaction $transaction): string
    {
        $key = Settlement::usesStoreGateway($transaction)
            ? (store_options($transaction->store_id)->restaurant_fatora_api_key ?? null)
            : config('settings.fatora_api_key');

        return is_scalar($key) ? trim((string) $key) : '';
    }
}
