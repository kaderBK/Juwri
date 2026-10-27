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
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * G8 noon Payments (noonpayments.com) — KSA/UAE/Egypt redirect gateway. P1-23 part 5: moved from the hand-written
 * NoonController into the adapter family; same routes, returnUrl, webhook signature and stored `details.noon_order_id`,
 * so an order initiated by the old controller still settles here.
 *
 * initiate: POST /order apiOperation=INITIATE (reference = our id) → result.checkoutData.postUrl.
 *
 * Two independent gates:
 * 1. WEBHOOK AUTHENTICITY (E2): `signature` = base64(HMAC-SHA512("<comma-joined signed fields>", secret)) over
 *    orderId,orderStatus,eventId,eventType,timeStamp (+ originalOrderId, merchantOrderReference, attemptNumber when
 *    present), values verbatim (docs.noonpayments.com/webhook; noon's own test vector in NoonWebhookSignatureTest).
 *    The secret is the webhook secret of the account the referenced transaction is paid into. Fail closed: no
 *    signature, wrong signature or no secret → 401, no outbound call, no side effect.
 * 2. SETTLEMENT TRUTH: never from the body — GET /order/{id} with the transaction's OWN account; the order must name
 *    that transaction and the noon order stored at INITIATE, be CAPTURED/SALE/SUCCESS, for the exact amount and
 *    currency; settles once. Without a reference only the platform account can be asked, so only a platform purchase
 *    may settle that way — never an order or a store payment.
 * The customer return is a browser redirect noon does not sign: gate 2 alone governs it.
 *
 * Keys: the STORE's for orders and store payments ({@see Settlement::usesStoreGateway()}), the platform's otherwise.
 * No refund API wired.
 */
class NoonAdapter implements GatewayAdapter
{
    private const BASE_SANDBOX = 'https://api-test.noonpayments.com/payment/v1';

    private const BASE_LIVE = 'https://api.noonpayments.com/payment/v1';

    /** Signed fields, in noon's documented order. */
    private const SIGNED_FIELDS = ['orderId', 'orderStatus', 'eventId', 'eventType', 'timeStamp'];

    /** Signed only when the event carries them (allowedRetry orders, retry attempts). */
    private const SIGNED_FIELDS_OPTIONAL = ['originalOrderId', 'merchantOrderReference', 'attemptNumber'];

    public function key(): string
    {
        return 'noon';
    }

    public function initiate(Transaction $transaction): Response
    {
        $cancelUrl = Settlement::cancelUrl($transaction);
        $creds = $this->creds($transaction);
        if (! $this->configured($creds)) {
            app_alert_error(___('This payment method is not configured.'));

            return redirect($cancelUrl);
        }
        // The amount at noon's own decimals (3 for KWD/BHD/OMR), or a refusal — never rounded.
        $currency = $this->currency($transaction);
        [$amount, $refusal] = GatewayCurrencies::amountOrRefusal('noon', $transaction, $currency);
        if ($amount === null) {
            app_alert_error((string) $refusal);

            return redirect($cancelUrl);
        }

        try {
            $resp = Http::withHeaders(['Authorization' => $this->authHeader($creds)])->acceptJson()->post($creds['base'].'/order', [
                'apiOperation' => 'INITIATE',
                'order' => [
                    'amount' => (float) $amount, // a JSON number; the exact string above
                    'currency' => $currency,
                    'reference' => (string) $transaction->id,
                    'category' => 'pay',
                ],
                'configuration' => [
                    'returnUrl' => route('ipn', 'noon'),
                    'paymentAction' => 'Sale',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[noon] INITIATE failed: '.$e->getMessage());
            app_alert_error(___('The payment service is unavailable right now. Please try again.'));

            return redirect($cancelUrl);
        }

        $postUrl = $resp->json('result.checkoutData.postUrl');
        if ($resp->successful() && (int) $resp->json('resultCode') === 0 && is_string($postUrl) && $postUrl !== '') {
            // WI-06d: remember noon's order id for this transaction.
            $noonOrderId = $resp->json('result.order.id');
            if (is_scalar($noonOrderId) && (string) $noonOrderId !== '') {
                $details = (array) ($transaction->details ?? []);
                $details['noon_order_id'] = (string) $noonOrderId;
                $transaction->forceFill(['details' => $details])->save();
            }

            return redirect()->away($postUrl);
        }

        Log::warning('[noon] INITIATE rejected: '.$resp->status());
        app_alert_error(___('Could not start the payment. Please try again.'));

        return redirect($cancelUrl);
    }

    /** Customer return (configuration.returnUrl): not signed by noon — gate 2 (GET order) alone decides. */
    public function handleReturn(Request $request): Response
    {
        $ref = $this->extractReference($request);
        // Context BEFORE settling (an order payment is consumed by the settlement).
        $before = $ref !== null ? Transaction::find($ref) : null;
        $settled = $this->verifyAndSettle($this->extractOrderId($request), $ref);
        $after = $ref !== null ? Transaction::find($ref) : null;

        return Settlement::confirmingForBuyer($before, $settled || ($after !== null && $after->status === Transaction::STATUS_SUCCESS));
    }

    /** Webhook: gate 1 (signature with the referenced transaction's account secret), then gate 2. Ack 200 when signed. */
    public function handleWebhook(Request $request): Response
    {
        $payload = $this->payloadArray($request);
        $reference = $this->extractReference($request);

        // Without a known transaction there is no secret to verify against and nothing to settle — the normal shape
        // of a retry arriving after settlement: ack, no lookup resolved, no outbound call, no state change.
        $probe = $reference !== null ? Transaction::find($reference) : null;
        if ($probe === null) {
            return response('ok', 200);
        }
        if (! self::signatureIsValid($payload, $this->webhookSecret($probe))) {
            Log::warning('[noon] webhook rejected: missing or invalid signature');

            return response('invalid signature', 401);
        }

        $this->verifyAndSettle($this->extractOrderId($request), $reference);

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
     * Constant-time verification of noon's webhook signature; absent secret / signature / mismatch → false.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function signatureIsValid(array $payload, ?string $secret): bool
    {
        if ($secret === null || $secret === '') {
            return false;
        }
        $provided = self::scalar($payload['signature'] ?? null);
        if ($provided === '') {
            return false;
        }
        $parts = [];
        foreach (self::SIGNED_FIELDS as $field) {
            $parts[] = self::scalar($payload[$field] ?? null);
        }
        // Appended ONLY when present — empty placeholders would change the hashed string.
        foreach (self::SIGNED_FIELDS_OPTIONAL as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null && $payload[$field] !== '') {
                $parts[] = self::scalar($payload[$field]);
            }
        }

        return hash_equals(base64_encode(hash_hmac('sha512', implode(',', $parts), $secret, true)), $provided);
    }

    /**
     * Gate 2: GET the noon order with the account of OUR pending transaction and settle it once when it is captured
     * for this transaction, its exact amount and currency.
     *
     * @param  string|null  $noonOrderId  noon's order id (from the webhook/return)
     * @param  string|null  $reference  OUR transaction id (from the webhook/return)
     */
    public function verifyAndSettle(?string $noonOrderId, ?string $reference): bool
    {
        $noonOrderId = $this->safeOrderId($noonOrderId);
        if ($noonOrderId === null) {
            return false;
        }

        $hasReference = $reference !== null && $reference !== '';
        $transaction = $hasReference ? $this->pending($reference) : null;
        if ($hasReference && $transaction === null) {
            return false; // unknown or already settled (idempotent)
        }
        if ($transaction !== null && ! $this->orderBelongsTo($transaction, $noonOrderId)) {
            return false;
        }
        $creds = $transaction !== null ? $this->creds($transaction) : $this->platformCreds();
        if (! $this->configured($creds)) {
            return false;
        }

        try {
            $resp = Http::withHeaders(['Authorization' => $this->authHeader($creds)])->acceptJson()->get($creds['base'].'/order/'.$noonOrderId);
        } catch (\Throwable $e) {
            Log::warning('[noon] GET order failed: '.$e->getMessage());

            return false;
        }
        if (! $resp->successful()) {
            return false;
        }

        $status = strtoupper((string) $resp->json('result.order.status'));
        $orderRef = $resp->json('result.order.reference');
        $paidAmt = $resp->json('result.order.amount');
        $paidCurr = strtoupper((string) $resp->json('result.order.currency'));
        if (! in_array($status, ['CAPTURED', 'SUCCESS', 'SALE'], true) || ! is_scalar($orderRef) || (string) $orderRef === '') {
            return false;
        }

        if ($transaction !== null) {
            if ((string) $orderRef !== (string) $transaction->id) {
                Log::warning("[noon] order {$noonOrderId} names reference {$orderRef}, not tx {$transaction->id}");

                return false;
            }
        } else {
            $transaction = $this->pending($orderRef);
            if ($transaction === null) {
                return false;
            }
            // Asked on the PLATFORM account: never an order or a store payment (paid into the store's account).
            if (Settlement::usesStoreGateway($transaction) || ! $this->orderBelongsTo($transaction, $noonOrderId)) {
                Log::warning("[noon] platform order {$noonOrderId} cannot settle tx {$transaction->id}");

                return false;
            }
        }

        $ourCurr = $this->currency($transaction);
        if (! GatewayMinorUnits::paidMatches('noon', $paidAmt, $transaction->money('amount'), $ourCurr)) {
            Log::warning("[noon] amount mismatch tx {$transaction->id}: paid ".(is_scalar($paidAmt) ? (string) $paidAmt : '-'));

            return false;
        }
        if ($paidCurr !== '' && $paidCurr !== $ourCurr) {
            Log::warning("[noon] currency mismatch tx {$transaction->id}: {$paidCurr} vs {$ourCurr}");

            return false;
        }

        // J25-7: no event id — "noon confirmed this payment paid"; a repeat or concurrent copy is a no-op.
        return WebhookReceipts::once('noon', WebhookReceipts::key($transaction->id, 'paid'), function () use ($transaction) {
            Settlement::settle($transaction, 'noon', null, claim: true);

            return true;
        }, true);
    }

    /* ------------------------------------------------------------ internals */

    private function currency(Transaction $transaction): string
    {
        return strtoupper((string) ($transaction->currency_code ?: 'SAR'));
    }

    /** The webhook secret (NOT the API key; generated per endpoint in the noon portal) of the transaction's account. */
    private function webhookSecret(Transaction $probe): ?string
    {
        $secret = Settlement::usesStoreGateway($probe)
            ? (store_options($probe->store_id)->restaurant_noon_webhook_secret ?? null)
            : config('settings.noon_webhook_secret');
        $secret = is_string($secret) ? trim($secret) : '';

        return $secret === '' ? null : $secret;
    }

    /**
     * The event body with EXACT scalar text (noon order ids are bigints; the signature covers their literal text).
     *
     * @return array<string, mixed>
     */
    private function payloadArray(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ($raw !== '') {
            $decoded = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->all();
    }

    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** noon's order id goes into the GET-order URL: opaque-token charset only (no "../../refund"). */
    private function safeOrderId(?string $orderId): ?string
    {
        $orderId = trim((string) $orderId);

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $orderId) === 1 ? $orderId : null;
    }

    private function pending(mixed $id): ?Transaction
    {
        if (! is_scalar($id) || ! ctype_digit((string) $id)) {
            return null;
        }

        return Transaction::where([['id', (int) $id], ['status', null], ['payment_id', null]])->first();
    }

    /** noon's order id matches the one stored at INITIATE (when one was stored). */
    private function orderBelongsTo(Transaction $transaction, string $noonOrderId): bool
    {
        $stored = (string) ($transaction->details->noon_order_id ?? '');
        if ($stored !== '' && $stored !== $noonOrderId) {
            Log::warning("[noon] order {$noonOrderId} does not belong to tx {$transaction->id}");

            return false;
        }

        return true;
    }

    private function extractOrderId(Request $request): ?string
    {
        $id = $request->input('orderId') ?? $request->input('order.id') ?? $request->input('order_id') ?? $request->input('id');

        return is_scalar($id) ? (string) $id : null;
    }

    private function extractReference(Request $request): ?string
    {
        $ref = $request->input('reference') ?? $request->input('order.reference') ?? $request->input('merchantOrderReference') ?? $request->input('merchantReference');

        return is_scalar($ref) && (string) $ref !== '' ? (string) $ref : null;
    }

    /** @param  array{business_id: string, application_id: string, api_key: string, base: string}  $creds */
    private function configured(array $creds): bool
    {
        return $creds['business_id'] !== '' && $creds['application_id'] !== '' && $creds['api_key'] !== '';
    }

    /**
     * Authorization: Key base64("{business}.{application}:{apiKey}").
     *
     * @param  array{business_id: string, application_id: string, api_key: string, base: string}  $creds
     */
    private function authHeader(array $creds): string
    {
        return 'Key '.base64_encode($creds['business_id'].'.'.$creds['application_id'].':'.$creds['api_key']);
    }

    /** @return array{business_id: string, application_id: string, api_key: string, base: string} */
    private function creds(Transaction $transaction): array
    {
        if (! Settlement::usesStoreGateway($transaction)) {
            return $this->platformCreds();
        }
        $opts = store_options($transaction->store_id);

        return $this->shape($opts->restaurant_noon_business_id ?? null, $opts->restaurant_noon_application_id ?? null, $opts->restaurant_noon_api_key ?? null, gateway_is_sandbox($opts->restaurant_noon_sandbox_mode ?? null));
    }

    /** @return array{business_id: string, application_id: string, api_key: string, base: string} */
    private function platformCreds(): array
    {
        return $this->shape(config('settings.noon_business_id'), config('settings.noon_application_id'), config('settings.noon_api_key'), gateway_is_sandbox(config('settings.noon_sandbox', true)));
    }

    /** @return array{business_id: string, application_id: string, api_key: string, base: string} */
    private function shape(mixed $business, mixed $application, mixed $key, bool $sandbox): array
    {
        $s = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';

        return GatewaySandbox::gate('noon', $sandbox, ['business_id' => $s($business), 'application_id' => $s($application), 'api_key' => $s($key), 'base' => $sandbox ? self::BASE_SANDBOX : self::BASE_LIVE]);
    }
}
