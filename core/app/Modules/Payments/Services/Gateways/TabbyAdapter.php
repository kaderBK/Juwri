<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * G5 Tabby BNPL (tabby.ai) — GCC hosted checkout session. P1-23 part 5: moved from the hand-written TabbyController
 * into the adapter family; same routes, merchant URLs and `payment_id` binding, so a session opened by the old
 * controller still settles here.
 *
 * initiate: a v2 checkout session (Bearer secret_key); the Tabby payment id is kept in `payment_id`; the buyer goes to
 * `web_url`. Two-phase money: an AUTHORIZED payment is CAPTURED (full capture auto-closes to CLOSED = settled).
 * handleWebhook: Tabby webhooks are NOT HMAC-signed — the body is only a trigger. The custom header `X-Tabby-Auth` must
 * equal the secret of the account the transaction is paid into (no header / wrong → 401, no call), the payment must be
 * the one stored at initiate, and it is RE-FETCHED (GET /payments/{id}) — its status, transaction, exact amount and
 * currency are checked before any capture; settles once.
 *
 * Keys: the STORE's for orders and store payments ({@see Settlement::usesStoreGateway()}), the platform's otherwise.
 * The return is display-only. No refund API wired.
 */
class TabbyAdapter implements GatewayAdapter
{
    /** Keys select the environment; the host is the same for sandbox + live. */
    private const BASE = 'https://api.tabby.ai';

    /** Custom auth header Tabby is configured to send back on every webhook. */
    private const AUTH_HEADER = 'X-Tabby-Auth';

    public function key(): string
    {
        return 'tabby';
    }

    public function initiate(Transaction $transaction): Response
    {
        if (($invalid = GatewayBuyer::requireOrderContact($transaction)) !== null) {
            return $invalid;
        }
        $cancelUrl = Settlement::cancelUrl($transaction);
        $buyer = GatewayBuyer::of($transaction);

        $creds = $this->creds($transaction);
        if ($creds['secret'] === '' || $creds['merchant_code'] === '') {
            app_alert_error(___('This payment method is not configured.'));

            return redirect($cancelUrl);
        }

        $currency = $this->currency($transaction);
        // Currency-aware precision: fils currencies (KWD/BHD/OMR) keep 3 decimals — see GatewayMinorUnits.
        $amount = GatewayMinorUnits::majorStringFromMoney($transaction->money('amount'), $currency);

        try {
            $resp = Http::withToken($creds['secret'])->acceptJson()->post(self::BASE.'/api/v2/checkout', [
                'payment' => [
                    'amount' => $amount,
                    'currency' => $currency,
                    'buyer' => ['phone' => $buyer['phone'], 'email' => $buyer['email'], 'name' => $buyer['name']],
                    'order' => [
                        'reference_id' => (string) $transaction->id,
                        'items' => [[
                            'title' => $transaction->product_name ?: 'Order',
                            'quantity' => 1,
                            'unit_price' => $amount,
                            'reference_id' => (string) $transaction->id,
                        ]],
                    ],
                ],
                'lang' => 'en',
                'merchant_code' => $creds['merchant_code'],
                'merchant_urls' => [
                    'success' => route('ipn', 'tabby'),
                    'cancel' => route('ipn', 'tabby'),
                    'failure' => route('ipn', 'tabby'),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[tabby] checkout failed: '.$e->getMessage());
            app_alert_error(___('The payment service is unavailable right now. Please try again.'));

            return redirect($cancelUrl);
        }

        // Tabby returns the hosted session URL; a pre-rejected buyer gets none (availability lives under configuration).
        $webUrl = $resp->json('web_url') ?: $resp->json('configuration.available_products.installments.0.web_url');
        $pid = $resp->json('payment.id');
        if ($resp->successful() && is_string($webUrl) && $webUrl !== '' && is_scalar($pid) && (string) $pid !== '') {
            // WI-06e: the webhook settles this transaction only from this payment.
            $transaction->update(['payment_id' => (string) $pid]);

            return redirect()->away($webUrl);
        }

        Log::warning('[tabby] checkout rejected: '.$resp->status());
        app_alert_error(___('Could not start the payment. Please try again.'));

        return redirect($cancelUrl);
    }

    private static function isTransactionId(mixed $ref): bool
    {
        return is_scalar($ref) && ctype_digit((string) $ref) && (int) $ref > 0;
    }

    /** Customer return — display-only, never settles. Tabby appends `payment_id`. */
    public function handleReturn(Request $request): Response
    {
        $ref = $request->input('order.reference_id') ?: $request->input('reference_id') ?: $request->input('id');
        $transaction = self::isTransactionId($ref) ? Transaction::find((int) $ref) : null;
        $paymentId = $request->input('payment_id');
        if ($transaction === null && is_string($paymentId) && $paymentId !== '') {
            $transaction = Transaction::where('payment_id', $paymentId)->first();
        }

        return Settlement::confirmingForBuyer($transaction);
    }

    public function handleWebhook(Request $request): Response
    {
        $paymentId = $request->input('id') ?: $request->input('payment.id');
        $reference = $request->input('order.reference_id') ?: $request->input('reference_id');
        if (! is_scalar($reference) || (string) $reference === '') {
            return response('missing ref', 200);
        }
        // p5p: a non-numeric reference is never one of our transaction ids (PostgreSQL refused the bigint cast and
        // aborted the request's transaction); it resolves to no transaction, so the platform secret gates it.
        $reference = self::isTransactionId($reference) ? (int) $reference : 0;

        // Resolve the candidate from OUR reference FIRST: it selects the account whose secret the header must carry.
        $probe = $reference > 0 ? Transaction::find($reference) : null;
        $secret = $probe !== null ? $this->creds($probe)['secret'] : $this->platformSecret();

        // SECURITY GATE: no header / wrong header → 401, NO re-fetch, NO side effects.
        $provided = $request->header(self::AUTH_HEADER);
        if ($secret === '' || ! is_string($provided) || $provided === '' || ! hash_equals($secret, $provided)) {
            return response('unauthorized', 401);
        }

        if (! is_scalar($paymentId) || (string) $paymentId === '') {
            return response('missing id', 200);
        }
        $paymentId = (string) $paymentId;

        // WI-06e: only a pending transaction settles, and only from the Tabby payment created for it at initiate.
        $transaction = $reference > 0 ? Transaction::where([['id', $reference], ['status', null]])->first() : null;
        if ($transaction === null) {
            return response('ok', 200); // unknown or already settled
        }
        $expectedPaymentId = (string) ($transaction->payment_id ?? '');
        if ($expectedPaymentId === '' || ! hash_equals($expectedPaymentId, $paymentId)) {
            Log::warning("[tabby] payment {$paymentId} does not belong to tx {$transaction->id}");

            return response('payment mismatch', 200);
        }

        // RE-FETCH the payment — the retrieve API is the source of truth.
        try {
            $resp = Http::withToken($secret)->acceptJson()->get(self::BASE.'/api/v2/payments/'.rawurlencode($paymentId));
        } catch (\Throwable $e) {
            Log::warning('[tabby] retrieve failed: '.$e->getMessage());

            return response('unverified', 200);
        }
        if (! $resp->successful()) {
            return response('unverified', 200);
        }

        // Retrieve API is UPPER, webhooks lower — force UPPER. rejected / expired / pending never settle.
        $status = strtoupper((string) $resp->json('status'));
        if (! in_array($status, ['AUTHORIZED', 'CLOSED'], true)) {
            return response('not settled', 200);
        }

        // This payment, for this transaction, for its exact amount and currency — checked BEFORE capturing.
        if ((string) $resp->json('id') !== $paymentId || (string) $resp->json('order.reference_id') !== (string) $transaction->id) {
            Log::warning("[tabby] retrieved payment {$paymentId} is not for tx {$transaction->id}");

            return response('payment mismatch', 200);
        }
        $paidAmount = $resp->json('amount');
        $paidCurr = strtoupper((string) $resp->json('currency'));
        $ourCurr = $this->currency($transaction);
        if (! GatewayMinorUnits::paidMatches('tabby', $paidAmount, $transaction->money('amount'), $ourCurr)) {
            Log::warning("[tabby] amount mismatch tx {$transaction->id}: paid ".(is_scalar($paidAmount) ? (string) $paidAmount : '-'));

            return response('amount mismatch', 200);
        }
        if ($paidCurr !== $ourCurr) {
            Log::warning("[tabby] currency mismatch tx {$transaction->id}: {$paidCurr} vs {$ourCurr}");

            return response('currency mismatch', 200);
        }

        // AUTHORIZED needs a capture of exactly what was authorised (full capture auto-closes → CLOSED = settled).
        if ($status === 'AUTHORIZED') {
            try {
                $cap = Http::withToken($secret)->acceptJson()->post(self::BASE.'/api/v2/payments/'.rawurlencode($paymentId).'/captures', [
                    'amount' => GatewayMinorUnits::majorStringFromMoney($transaction->money('amount'), $ourCurr),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[tabby] capture failed: '.$e->getMessage());

                return response('capture failed', 200);
            }
            if (! $cap->successful()) {
                Log::warning('[tabby] capture rejected: '.$cap->status());

                return response('capture failed', 200);
            }
        }

        // J25-7: "tabby confirmed this payment paid" (checked with the provider above) — a repeated or concurrent
        // confirmation is a no-op (WebhookReceipts + the pending claim).
        return WebhookReceipts::once('tabby', WebhookReceipts::key($transaction->id, 'paid'), function () use ($transaction) {
            Settlement::settle($transaction, 'tabby', null, claim: true);

            return response('ok', 200);
        }, fn () => response('ok', 200));
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        return Settlement::noAutomaticRefund();
    }

    /* ------------------------------------------------------------ internals */

    private function currency(Transaction $transaction): string
    {
        return strtoupper((string) ($transaction->currency_code ?: 'SAR'));
    }

    private function platformSecret(): string
    {
        $secret = config('settings.tabby_secret_key');

        return GatewaySandbox::key('tabby', is_scalar($secret) ? (string) $secret : '');
    }

    /** @return array{secret: string, merchant_code: string} */
    private function creds(Transaction $transaction): array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $opts = store_options($transaction->store_id);
            [$secret, $code] = [$opts->restaurant_tabby_secret_key ?? null, $opts->restaurant_tabby_merchant_code ?? null];
        } else {
            [$secret, $code] = [config('settings.tabby_secret_key'), config('settings.tabby_merchant_code')];
        }

        // Lane D: a test key (sk_test_…) only while payment sandboxes are allowed.
        return ['secret' => GatewaySandbox::key('tabby', is_scalar($secret) ? (string) $secret : ''), 'merchant_code' => is_scalar($code) ? (string) $code : ''];
    }
}
