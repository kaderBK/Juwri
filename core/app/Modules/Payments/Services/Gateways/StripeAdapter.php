<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Money\Money;
use Juwri\Core\Support\StripeManagedPayments;
use Juwri\Modules\Payments\Contracts\OrderPaymentHandler;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Disputes\DisputeService;
use Juwri\Modules\Payments\Services\GatewayCurrencies;
use Juwri\Modules\Payments\Services\GatewayRounding;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\RenewalAmountHold;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\AccountPages;
use Juwri\Modules\Payments\Support\Fulfilment;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe Checkout (https://docs.stripe.com/api/checkout/sessions), spoken over
 * the REST API with Laravel's HTTP client.
 *
 * One-time payments: a Checkout Session in `payment` mode; the buyer returns
 * to `ipn/stripe?payment_id={CHECKOUT_SESSION_ID}&pay_mode=one_time`, we read
 * the session back and settle when it is paid and complete (the signed
 * `checkout.session.completed` event settles it too if the buyer never
 * returns). Recurring plans and monthly add-ons: a `subscription` session on
 * a day-interval Plan (30 or 365 days); the pending transaction is dropped and
 * each signed `invoice.paid` event settles through
 * CheckoutController::processWebhook with the transaction attributes that
 * travel as subscription metadata. `charge.dispute.*` events feed the
 * disputes module.
 *
 * Keys: store orders use the store's own secret key (BYOK), everything else the
 * platform key. A test-mode key (sk_test_…) and a test-mode event (`livemode: false`) are accepted only while payment
 * sandboxes are allowed ({@see GatewaySandbox}). Webhooks are verified with the platform webhook signing secret
 * (`Stripe-Signature`: HMAC-SHA256 of "<t>.<body>", 5-minute tolerance).
 */
class StripeAdapter implements AuthorisesPayments, GatewayAdapter, StoreWebhooks
{
    public const API = 'https://api.stripe.com/v1';

    /** Seconds a signed webhook timestamp may be away from now (Stripe's default). */
    public const SIGNATURE_TOLERANCE = 300;

    public function key(): string
    {
        return 'stripe';
    }

    public function initiate(Transaction $transaction): Response
    {
        try {
            $secret = $this->secretFor($transaction);
            if ($secret === '') {
                throw new RuntimeException(___('This payment method is not configured.'));
            }

            $url = $this->payMode($transaction) === 'recurring'
                ? $this->startSubscription($transaction, $secret)
                : $this->startOneTimePayment($transaction, $secret);

            return redirect()->away($url);
        } catch (\Throwable $e) {
            PaymentLog::failure('stripe', $e, ['transaction_id' => $transaction->id]);
            app_alert_error($this->errorMessage($e));

            return back();
        }
    }

    public function handleReturn(Request $request): Response
    {
        if ($request->input('pay_mode') !== 'one_time') {
            // Subscriptions are credited by the invoice.paid webhook.
            app_alert_success(___('Payment successful'));

            return redirect()->to(AccountPages::url());
        }

        $sessionId = (string) $request->input('payment_id', '');
        $transaction = $sessionId === '' ? null : Settlement::pending(['payment_id' => $sessionId]);
        if ($transaction === null) {
            $already = $sessionId === '' ? null : Transaction::query()
                ->where('payment_id', $sessionId)
                ->where('status', Transaction::STATUS_SUCCESS)
                ->first();
            if ($already !== null && ! Settlement::usesStoreGateway($already)) {
                app_alert_success(___('Payment successful'));

                return redirect()->to(AccountPages::url(match ($already->transaction_method) {
                    'wallet_topup' => 'wallet.index',
                    'credit_pack' => 'credits.index',
                    default => 'subscription',
                }));
            }

            return Settlement::invalid();
        }

        try {
            $session = $this->client($this->secretFor($transaction))
                ->get('/checkout/sessions/'.rawurlencode($sessionId))->throw()->json();

            if (self::holdsCard($transaction)) {
                // P3-09: a weighed basket's card hold — authorised, captured after weighing
                return $this->authorised((array) $session, $transaction) || (string) $transaction->fresh()?->status === CardHolds::STATUS_AUTHORISED
                    ? response(app(OrderPaymentHandler::class)->buyerPage($transaction->fresh() ?? $transaction))
                    : Settlement::failed();
            }

            if (($session['status'] ?? null) === 'complete' && ($session['payment_status'] ?? null) === 'unpaid') {
                return Settlement::confirmingForBuyer($transaction);
            }

            if (! $this->sessionSettles($session, $transaction)) {
                return Settlement::failed();
            }

            // J25-7: credit durable via the outbox, same answer to the buyer.
            return Settlement::settleForBuyer($transaction, 'stripe', (string) $session['id'], match ($transaction->transaction_method) {
                'wallet_topup' => 'wallet.index', 'credit_pack' => 'credits.index', default => 'subscription'
            });
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('stripe', $e);

            return Settlement::failed();
        }
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();

        if (! $this->signatureIsValid($payload, (string) $request->header('Stripe-Signature', ''), (string) config('settings.stripe_webhook_secret', ''))) {
            PaymentLog::webhookRejected('stripe', 'invalid signature');

            return $this->answer(400, 'Invalid Stripe signature.');
        }

        $event = json_decode($payload, true);
        if (! is_array($event) || ! isset($event['type'])) {
            PaymentLog::webhookRejected('stripe', 'invalid payload');

            return $this->answer(400, 'Invalid payload.');
        }
        // Phase 4 item 18: an event from a test-mode account only while payment sandboxes are allowed.
        if (! GatewaySandbox::allowsEvent('stripe', ($event['livemode'] ?? true) !== false)) {
            PaymentLog::webhookRejected('stripe', 'test-mode event while sandboxes are off');

            return $this->answer(400, 'Test-mode events are not accepted.');
        }

        // J25-7: once per verified event (WebhookReceipts): a re-delivered or concurrent copy is a 200 no-op.
        return WebhookReceipts::once('stripe', WebhookReceipts::key($event['id'] ?? null, WebhookReceipts::fingerprint($request)), function () use ($event, $payload) {
            $type = (string) $event['type'];
            $object = $event['data']['object'] ?? [];

            if ($type === 'invoice.paid') {
                return $this->invoicePaid($payload);
            }

            if (str_starts_with($type, 'charge.dispute.')) {
                if (feature_enabled('disputes')) {
                    try {
                        app(DisputeService::class)->upsertFromStripe($event);
                    } catch (\Throwable $e) {
                        Log::warning('[disputes] webhook handling failed: '.$e->getMessage());
                    }
                }

                return $this->answer(200, 'dispute recorded');
            }

            if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && is_array($object)) {
                $this->sessionCompleted($object);
            }

            if ($type === 'checkout.session.async_payment_failed' && is_array($object)) {
                $this->sessionFailed($object);
            }

            if ($type === 'payment_intent.succeeded' && is_array($object)) {
                $this->intentSucceeded($object);
            }

            return $this->answer(200, 'successful');
        }, fn () => $this->answer(200, 'duplicate'));
    }

    /**
     * A store's OWN Stripe account notifying `webhook/stripe/store/{store}`: signed with THAT store's webhook signing
     * secret (`restaurant_stripe_webhook_secret`, vaulted) — never the platform's or another store's; no secret = 400.
     * Only that store's own pending payments settle here (a Checkout session or a wallet PaymentIntent of a transaction
     * paid into the store's account), under the same checks as the return, claimed once; once per event (receipts).
     */
    public function handleStoreWebhook(Request $request, int $storeId): Response
    {
        $payload = $request->getContent();
        $secret = trim((string) (store_options($storeId, 'restaurant_stripe_webhook_secret') ?? ''));
        if ($secret === '' || ! $this->signatureIsValid($payload, (string) $request->header('Stripe-Signature', ''), $secret)) {
            PaymentLog::webhookRejected('stripe', 'invalid store signature', null, ['store_id' => $storeId]);

            return $this->answer(400, 'Invalid Stripe signature.');
        }
        $event = json_decode($payload, true);
        if (! is_array($event) || ! isset($event['type'])) {
            return $this->answer(400, 'Invalid payload.');
        }
        if (! GatewaySandbox::allowsEvent('stripe', ($event['livemode'] ?? true) !== false)) {
            PaymentLog::webhookRejected('stripe', 'test-mode event while sandboxes are off', null, ['store_id' => $storeId]);

            return $this->answer(400, 'Test-mode events are not accepted.');
        }

        $eventKey = WebhookReceipts::key('store', $storeId, $event['id'] ?? null, WebhookReceipts::fingerprint($request));

        return WebhookReceipts::once('stripe', $eventKey, function () use ($event, $storeId) {
            $object = $event['data']['object'] ?? [];
            $id = is_array($object) ? (string) ($object['id'] ?? '') : '';
            $transaction = $id === '' ? null : Settlement::pending(['payment_id' => $id, 'store_id' => $storeId]);
            if ($transaction === null || ! Settlement::usesStoreGateway($transaction)) {
                return $this->answer(200, 'nothing pending for this store');
            }
            if ($event['type'] === 'checkout.session.completed') {
                $this->sessionCompleted($object);
            } elseif ($event['type'] === 'payment_intent.succeeded') {
                $this->intentSucceeded($object);
            }

            return $this->answer(200, 'successful');
        }, fn () => $this->answer(200, 'duplicate'));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        $own = $this->currencyFor($transaction);

        return RefundGuard::run($transaction, $amount, $currency, $this->key(), GatewayMinorUnits::decimals($own), function (Money $money, string $idempotencyKey) use ($transaction) {
            try {
                $client = $this->client($this->secretFor($transaction));
                $reference = (string) $transaction->payment_id;
                $intent = str_starts_with($reference, 'cs_')
                    ? (string) ($client->get('/checkout/sessions/'.rawurlencode($reference))->throw()->json('payment_intent') ?? '')
                    : $reference;
                if ($intent === '') {
                    return ['ok' => false, 'reference' => null, 'message' => 'No Stripe payment to refund.'];
                }

                // P6-80: the ledger entry id — a retry after a timeout reuses it, so Stripe returns the first refund.
                $refund = $client->withHeaders(['Idempotency-Key' => 'refund-'.$idempotencyKey])->post('/refunds', [
                    'payment_intent' => $intent,
                    'amount' => GatewayMinorUnits::fromMoney($money),
                ])->throw()->json();

                return ['ok' => true, 'reference' => $refund['id'] ?? null, 'message' => null];
            } catch (ConnectionException $e) {
                throw $e; // no answer: outcome unknown — RefundGuard keeps the reservation (bug batch 6)
            } catch (\Throwable $e) {
                return ['ok' => false, 'reference' => null, 'message' => $this->errorMessage($e)];
            }
        }, tenFils: true, idempotent: true);
    }

    /** P3-09: capture part of a PaymentIntent authorised with `capture_method=manual`. */
    public function capture(Transaction $transaction, Money $amount): array
    {
        try {
            $intent = $this->client($this->secretFor($transaction))
                ->post('/payment_intents/'.rawurlencode((string) $transaction->payment_id).'/capture', [
                    'amount_to_capture' => GatewayMinorUnits::fromMoney($amount),
                ])->throw()->json();
            if (($intent['status'] ?? null) !== 'succeeded') {
                return ['ok' => false, 'reference' => null, 'message' => 'Stripe did not capture the payment.'];
            }

            return ['ok' => true, 'reference' => (string) ($intent['latest_charge'] ?? $intent['id'] ?? ''), 'message' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reference' => null, 'message' => $this->errorMessage($e)];
        }
    }

    /** P3-09: cancel an uncaptured PaymentIntent (the hold is released). */
    public function void(Transaction $transaction): array
    {
        try {
            $intent = $this->client($this->secretFor($transaction))
                ->post('/payment_intents/'.rawurlencode((string) $transaction->payment_id).'/cancel')->throw()->json();

            return ['ok' => ($intent['status'] ?? null) === 'canceled', 'reference' => (string) ($intent['id'] ?? ''), 'message' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reference' => null, 'message' => $this->errorMessage($e)];
        }
    }

    /** P3-09: a store order that asks for a card hold (authorise now, capture the weighed amount later). */
    private static function holdsCard(Transaction $transaction): bool
    {
        return Settlement::usesStoreGateway($transaction) && data_get($transaction->details, 'capture') === 'manual'; // only store orders ask for a hold
    }

    /**
     * P3-09: a completed Checkout session of a card hold — its PaymentIntent must wait for capture
     * (`requires_capture`) for exactly the amount and currency we asked; then the hold is recorded (Stripe lets an
     * authorisation lapse after 7 days).
     *
     * @param  array<string, mixed>  $session
     */
    private function authorised(array $session, Transaction $transaction): bool
    {
        $intentId = (string) ($session['payment_intent'] ?? '');
        if (($session['status'] ?? null) !== 'complete' || $intentId === '') {
            return false;
        }
        $intent = $this->client($this->secretFor($transaction))->get('/payment_intents/'.rawurlencode($intentId))->throw()->json();
        $currency = $this->currencyFor($transaction);
        $expected = GatewayRounding::chargeMinor($this->key(), $transaction->money('amount'), $currency);
        if (($intent['status'] ?? null) !== 'requires_capture' || $expected === null
            || (int) ($intent['amount_capturable'] ?? -1) !== $expected || strtoupper((string) ($intent['currency'] ?? '')) !== $currency) {
            Log::warning("[stripe] card hold {$intentId} does not match transaction {$transaction->id}");

            return false;
        }

        return Settlement::authorise($transaction, 'stripe', $intentId, now()->addDays(7));
    }

    /* ------------------------------------------------------------ internals */

    private function secretFor(Transaction $transaction): string
    {
        $secret = Settlement::usesStoreGateway($transaction)
            ? (string) (store_options($transaction->store_id, 'restaurant_stripe_secret_key') ?? '')
            : (string) config('settings.stripe_secret_key', '');

        // Phase 4 item 18: a test-mode key works only while payment sandboxes are allowed.
        return GatewaySandbox::allowsKey('stripe', $secret) ? trim($secret) : '';
    }

    /** The transaction's own currency (gateway-currency bug fix: never the store's/platform's current one). */
    private function currencyFor(Transaction $transaction): string
    {
        return Settlement::currency($transaction, 'USD');
    }

    /**
     * The amount in Stripe minor units. 3-decimal currencies are charged in whole tens of fils: an amount between steps
     * is charged ROUNDED DOWN (KWD 12.345 → 12340) and the difference recorded on the transaction for the books
     * (GatewayRounding, owner decision 2026-10-01). Anything Stripe cannot charge otherwise (ISK fractions) is refused —
     * checked again here, not only in pay(). before: fromMoney() rounded half-up (12350); then (earlier today) refused.
     */
    private function chargeableMinor(Transaction $transaction, string $currency): int
    {
        $minor = GatewayRounding::chargeMinor($this->key(), $transaction->money('amount'), $currency);
        if ($minor === null) {
            throw new RuntimeException((string) GatewayCurrencies::refusal($this->key(), $transaction));
        }
        GatewayRounding::record($transaction, $this->key(), $currency);

        return $minor;
    }

    /** `recurring` or `one_time`. */
    private function payMode(Transaction $transaction): string
    {
        $method = $transaction->transaction_method;
        if ($method === 'order' || $method === 'wallet_topup' || $method === 'credit_pack') {
            return 'one_time';
        }
        if ($method === 'addon') {
            return $transaction->frequency === 'MONTHLY' ? 'recurring' : 'one_time';
        }
        if ($transaction->frequency === 'LIFETIME') {
            return 'one_time';
        }
        // A charge whose amount changes every period (per-branch plan quantity) is paid one period at a time.
        if (data_get($transaction->details, 'one_time_only') === true) {
            return 'one_time';
        }

        $setting = (string) (config('settings.stripe_payment_mode') ?: 'one_time');
        $mode = $setting === 'both' ? (string) request()->input('payment_mode', 'one_time') : $setting;

        return $mode === 'recurring' ? 'recurring' : 'one_time';
    }

    private function startOneTimePayment(Transaction $transaction, string $secret): string
    {
        $currency = $this->currencyFor($transaction);
        $title = Settlement::title($transaction);
        $managed = ! Settlement::usesStoreGateway($transaction) && StripeManagedPayments::enabled();

        $payload = StripeManagedPayments::session([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'client_reference_id' => (string) $transaction->id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $this->chargeableMinor($transaction, $currency),
                    'product_data' => StripeManagedPayments::product(
                        ['name' => $title, 'description' => $title],
                        StripeManagedPayments::TAX_SAAS_BUSINESS,
                        $managed,
                    ),
                ],
            ]],
            'success_url' => route('ipn', 'stripe').'?payment_id={CHECKOUT_SESSION_ID}&pay_mode=one_time',
            'cancel_url' => Settlement::cancelUrl($transaction),
        ], $managed);

        $session = $this->client($secret, $managed)
            ->post('/checkout/sessions', $payload + (self::holdsCard($transaction) ? ['payment_intent_data' => ['capture_method' => 'manual']] : []))
            ->throw()
            ->json();

        $transaction->update(['payment_id' => $session['id']]);

        return (string) $session['url'];
    }

    /**
     * Subscription checkout. The transaction's attributes ride along as metadata
     * (on the subscription, so every invoice carries them) and the pending row
     * is removed: each paid invoice creates its own transaction.
     */
    private function startSubscription(Transaction $transaction, string $secret): string
    {
        $managed = ! Settlement::usesStoreGateway($transaction) && StripeManagedPayments::enabled();
        $client = $this->client($secret, $managed);
        $currency = $this->currencyFor($transaction);
        $amount = $this->chargeableMinor($transaction, $currency);
        $productId = $this->productKey($transaction);

        $product = $client->get('/products/'.rawurlencode($productId));
        if ($product->status() === 404) {
            $client->post('/products', StripeManagedPayments::product([
                'id' => $productId,
                'name' => (string) $transaction->product_name,
            ], StripeManagedPayments::TAX_SAAS_BUSINESS, $managed))->throw();
        } else {
            $existingProduct = $product->throw()->json();
            $needsTax = $managed && ($existingProduct['tax_code'] ?? null) !== StripeManagedPayments::TAX_SAAS_BUSINESS;
            if (($existingProduct['name'] ?? null) !== $transaction->product_name || $needsTax) {
                $client->post('/products/'.rawurlencode($productId), StripeManagedPayments::product([
                    'name' => (string) $transaction->product_name,
                ], StripeManagedPayments::TAX_SAAS_BUSINESS, $managed))->throw();
            }
        }

        $planId = $productId.'_'.$transaction->frequency.'_'.$amount.'_'.$currency;
        $plan = $client->get('/plans/'.rawurlencode($planId));
        if ($plan->status() === 404) {
            $client->post('/plans', [
                'id' => $planId,
                'product' => $productId,
                'amount' => $amount,
                'currency' => strtolower($currency),
                'interval' => 'day',
                'interval_count' => $transaction->frequency === 'MONTHLY' ? 30 : 365,
            ])->throw();
        } else {
            $plan->throw();
        }

        $metadata = $this->metadata($transaction);
        $session = $client->post('/checkout/sessions', StripeManagedPayments::session([
            'mode' => 'subscription',
            'payment_method_types' => ['card'],
            'line_items' => [['price' => $planId, 'quantity' => 1]],
            'subscription_data' => ['metadata' => $metadata],
            'metadata' => $metadata,
            'success_url' => route('ipn', 'stripe').'?payment_id={CHECKOUT_SESSION_ID}&pay_mode=recurring',
            'cancel_url' => AccountPages::url(),
        ], $managed))->throw()->json();

        $transaction->delete();

        return (string) $session['url'];
    }

    /** Add-ons have no product row (product_id 0), so they are keyed by add-on type. */
    private function productKey(Transaction $transaction): string
    {
        if ($transaction->transaction_method === 'addon') {
            return 'addon_'.($transaction->details->type ?? 'x');
        }

        return (string) $transaction->product_id;
    }

    /**
     * The transaction's stored attributes as Stripe metadata (strings; empty
     * values left out).
     *
     * @return array<string, string>
     */
    private function metadata(Transaction $transaction): array
    {
        $metadata = [];
        foreach ($transaction->legacyAttributes() as $name => $value) {
            if ($value === null || $value === '' || is_array($value) || is_object($value)) {
                continue;
            }
            $metadata[$name] = (string) $value;
        }

        return $metadata;
    }

    /**
     * A returned session settles when paid, complete, and for the amount and currency we asked.
     *
     * @param  array<string, mixed>  $session
     */
    private function sessionSettles(array $session, Transaction $transaction): bool
    {
        if (($session['payment_status'] ?? null) !== 'paid' || ($session['status'] ?? null) !== 'complete') {
            return false;
        }

        $currency = $this->currencyFor($transaction);
        // The amount we asked Stripe for — the rounded-down step for a 3-decimal amount between steps (KWD 12.345 →
        // 12340, GatewayRounding); exact otherwise. before: the half-up 12350, so an over-charge settled.
        $expected = GatewayRounding::chargeMinor($this->key(), $transaction->money('amount'), $currency);
        if ($expected === null || (isset($session['amount_total']) && (int) $session['amount_total'] !== $expected)) {
            // before: `{$session['amount_total']}` here threw "undefined array key" when Stripe sent no amount_total
            Log::warning("[stripe] amount mismatch on transaction {$transaction->id}: paid ".(is_scalar($session['amount_total'] ?? null) ? $session['amount_total'] : '-').' expected '.($expected ?? '-'));

            return false;
        }
        if (isset($session['currency']) && strtoupper((string) $session['currency']) !== $currency) {
            Log::warning("[stripe] currency mismatch on transaction {$transaction->id}");

            return false;
        }

        return true;
    }

    /**
     * Fallback for a one-time payment whose buyer never came back to the return URL.
     *
     * @param  array<string, mixed>  $session
     */
    private function sessionCompleted(array $session): void
    {
        if (($session['mode'] ?? null) !== 'payment' || empty($session['id'])) {
            return;
        }
        $transaction = Settlement::pending(['payment_id' => (string) $session['id']]);
        if ($transaction !== null && self::holdsCard($transaction)) {
            $this->authorised($session, $transaction); // P3-09: a card hold, never settled as a payment here

            return;
        }
        if ($transaction === null || ! $this->sessionSettles($session, $transaction)) {
            return;
        }

        try {
            // J25-7: credit durable via the outbox; claimed — a return racing this webhook (or the store's own webhook) settles once
            Settlement::settle($transaction, 'stripe', (string) $session['id'], claim: true);
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('stripe', $e);
        }
    }

    /**
     * Delayed payment method (e.g. bank debit) failed after the checkout session completed.
     *
     * @param  array<string, mixed>  $session
     */
    private function sessionFailed(array $session): void
    {
        if (($session['mode'] ?? null) !== 'payment' || empty($session['id'])) {
            return;
        }
        $transaction = Settlement::pending(['payment_id' => (string) $session['id']]);
        if ($transaction === null) {
            return;
        }
        Transaction::query()
            ->whereKey($transaction->getKey())
            ->whereNull('status')
            ->update(['status' => Transaction::STATUS_FAILED]);
    }

    /**
     * Apple/Google Pay (WalletPaymentController::intent): the PaymentIntent's pending order transaction settles here —
     * before: nothing settled these payments (the order stayed unpaid after the customer was charged). Same rules as a
     * Checkout session: succeeded, our transaction (metadata), the transaction's currency, and EXACTLY the amount we
     * asked for (GatewayRounding::chargeMinor — the rounded-down step for a 3-decimal amount between steps). Settles
     * once (claimPaid); a replay finds nothing pending.
     *
     * @param  array<string, mixed>  $intent
     */
    private function intentSucceeded(array $intent): void
    {
        $id = (string) ($intent['id'] ?? '');
        $transaction = $id === '' ? null : Settlement::pending(['payment_id' => $id]);
        if ($transaction === null) {
            return;
        }
        $currency = $this->currencyFor($transaction);
        $expected = GatewayRounding::chargeMinor($this->key(), $transaction->money('amount'), $currency);
        $paid = $intent['amount_received'] ?? $intent['amount'] ?? null;
        $claimed = (string) ($intent['metadata']['transaction_id'] ?? '');
        if (($intent['status'] ?? null) !== 'succeeded' || $expected === null || ! is_numeric($paid) || (int) $paid !== $expected
            || strtoupper((string) ($intent['currency'] ?? '')) !== $currency || ($claimed !== '' && $claimed !== (string) $transaction->id)) {
            Log::warning("[stripe] payment intent {$id} does not match transaction {$transaction->id}: paid ".(is_scalar($paid) ? $paid : '-')
                .' '.strtoupper((string) ($intent['currency'] ?? '')).", expected {$expected} {$currency}");

            return;
        }

        try {
            Settlement::settle($transaction, 'stripe', $id, claim: true); // J25-7: credit durable via the outbox
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('stripe', $e);
        }
    }

    /** Each paid subscription invoice settles once through processWebhook (it refuses a repeated invoice id). */
    private function invoicePaid(string $payload): Response
    {
        $event = json_decode($payload);
        $invoice = $event->data->object ?? null;
        $metadata = $invoice->lines->data[0]->metadata ?? $invoice->metadata ?? null;
        $subscription = $invoice->subscription
            ?? $invoice->parent->subscription_details->subscription
            ?? null;

        if (! is_object($metadata) || get_object_vars($metadata) === [] || empty($invoice->id)) {
            return response()->json(['status' => 400], 400);
        }
        // The invoice must be in the currency the subscription was sold in (the checkout transaction's, carried in
        // the metadata).
        $sold = strtoupper(trim((string) ($metadata->currency_code ?? '')));
        $paid = strtoupper(trim((string) ($invoice->currency ?? '')));
        if ($sold !== '' && $paid !== '' && $sold !== $paid) {
            PaymentLog::webhookRejected('stripe', 'invoice currency mismatch', null, ['invoice' => (string) $invoice->id, 'paid' => $paid, 'expected' => $sold]);

            return response()->json(['status' => 400], 400);
        }

        /* BL-038 P1: a renewal must be for the amount the subscription was sold at — its plan price was created from
           the checkout amount (startSubscription), which the metadata carries. Before: only the currency was compared,
           so an invoice for any amount renewed the plan. Compared is the invoice's amount BEFORE customer balance and
           tax ({@see self::invoiceMatchesPlan()}): a balance credit lowers amount_paid and exclusive Stripe Tax raises
           it, both legitimately. A discount (a coupon added at Stripe), another price or currency is held: a review row
           + platform alert, acknowledged (200) so Stripe stops retrying — released by hand with
           `php artisan payments:settle-held-renewal <review transaction id>` once checked at Stripe. A subscription
           sold before its currency was carried is checked in the invoice's own currency. */
        $currency = $sold !== '' ? $sold : $paid;
        $expected = $currency !== '' && is_numeric($metadata->amount ?? null)
            ? Money::ofMajor((string) $metadata->amount, $currency) : null;
        if (! $this->invoiceMatchesPlan($invoice, $expected, $currency)) {
            RenewalAmountHold::record('stripe', $metadata, (string) $invoice->id, is_scalar($subscription) ? (string) $subscription : null, [
                'paid_minor' => is_numeric($invoice->amount_paid ?? null) ? (int) $invoice->amount_paid : null,
                'total_excluding_tax' => is_numeric($invoice->total_excluding_tax ?? null) ? (int) $invoice->total_excluding_tax : null,
                'subtotal' => is_numeric($invoice->subtotal ?? null) ? (int) $invoice->subtotal : null,
                'discount_minor' => self::invoiceDiscountMinor($invoice),
                'collected_minor' => self::collectedForPlan($invoice),
                'paid_currency' => $paid,
                'expected_minor' => $expected !== null ? GatewayRounding::chargeMinor($this->key(), $expected, $currency) : null,
                'expected_currency' => $sold,
            ]);

            return response()->json(['message' => 'held for review', 'status' => 200], 200);
        }
        if ($sold === '') {
            $metadata = clone $metadata;
            $metadata->currency_code = $currency; // settled in the currency the invoice was checked (and paid) in
        }

        $response = Fulfilment::webhook('stripe', $metadata, $invoice->id, $subscription);
        if ($response->getStatusCode() === 200) {
            // The plan price was set up rounded down to Stripe's step (startSubscription): book this invoice's rounding.
            $settled = Transaction::query()->where('transaction_gatway', 'stripe')->where('payment_id', (string) $invoice->id)->first();
            if ($settled !== null) {
                GatewayRounding::book($settled, 'stripe');
            }
        }

        return $response;
    }

    /**
     * BL-038 P1: whether a paid recurring invoice is for the plan amount the subscription was sold at. Stripe minor
     * units. The amount compared is `total_excluding_tax` (after discounts, before tax and before the customer balance
     * is applied), or `subtotal` (before discounts and balance) — `total` instead when the prices include their tax —
     * else, an invoice carrying none of them, `amount_paid`. A discount on the invoice never matches (the plan price is
     * already the agreed price). Matches the charge we set up (GatewayRounding: a 3-decimal currency rounded DOWN to
     * Stripe's 10-fils step) — or, for a subscription set up before that decision, the same total rounded UP to the
     * next step (they were rounded half-up then: at most one step over). No other tolerance.
     */
    private function invoiceMatchesPlan(object $invoice, ?Money $expected, string $currency): bool
    {
        $charge = $expected !== null ? GatewayRounding::chargeMinor($this->key(), $expected, $currency) : null;
        if ($charge === null || self::invoiceDiscountMinor($invoice) > 0) {
            return false;
        }
        $accepted = [$charge];
        $quote = GatewayRounding::quote($this->key(), $expected, $currency);
        if ($quote !== null) {
            $accepted[] = $quote['charged_minor'] + $quote['step_minor'];
        }
        /* With tax-INCLUSIVE prices total_excluding_tax is below the price by that tax; `total` (after discounts, before
           the customer balance) is then the price — a discount shows there too. Otherwise `subtotal` (before discounts:
           a discount is caught by the total_discount_amounts check above). */
        $second = self::hasInclusiveTax($invoice) ? ($invoice->total ?? null) : ($invoice->subtotal ?? null);
        $amounts = array_values(array_filter([$invoice->total_excluding_tax ?? null, $second], 'is_numeric'));
        if ($amounts === [] && is_numeric($invoice->amount_paid ?? null)) {
            $amounts = [$invoice->amount_paid];
        }
        foreach ($amounts as $amount) {
            if (in_array((int) $amount, $accepted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the invoice collected for the plan (minor units), before the customer balance and any added tax: `total` with
     * tax-inclusive prices, else `total_excluding_tax`, else `subtotal`, else `amount_paid`. Null when none is given.
     */
    private static function collectedForPlan(object $invoice): ?int
    {
        $candidates = self::hasInclusiveTax($invoice) ? [$invoice->total ?? null] : [];
        foreach ([...$candidates, $invoice->total_excluding_tax ?? null, $invoice->subtotal ?? null, $invoice->amount_paid ?? null] as $amount) {
            if (is_numeric($amount)) {
                return (int) $amount;
            }
        }

        return null;
    }

    /** Whether Stripe Tax added tax-inclusive amounts to the invoice (the price already contains that tax). */
    private static function hasInclusiveTax(object $invoice): bool
    {
        foreach ((array) ($invoice->total_tax_amounts ?? []) as $tax) {
            if ((bool) (is_object($tax) ? ($tax->inclusive ?? false) : (is_array($tax) ? ($tax['inclusive'] ?? false) : false))) {
                return true;
            }
        }

        return false;
    }

    /** The discounts Stripe applied to an invoice (minor units): coupons / promotion codes added at Stripe. */
    private static function invoiceDiscountMinor(object $invoice): int
    {
        $total = 0;
        foreach ((array) ($invoice->total_discount_amounts ?? []) as $discount) {
            $total += (int) (is_object($discount) ? ($discount->amount ?? 0) : (is_array($discount) ? ($discount['amount'] ?? 0) : 0));
        }

        return $total;
    }

    /**
     * Stripe-Signature: `t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">`
     * (several v1 values while a secret is being rolled).
     */
    private function signatureIsValid(string $payload, string $header, string $secret): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't') {
                $timestamp = $value;
            } elseif ($name === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return array_any($signatures, fn ($signature) => hash_equals($expected, $signature));
    }

    private function client(string $secret, bool $managedPayments = false): PendingRequest
    {
        $client = Http::baseUrl(self::API)->withToken($secret)->asForm()->acceptJson()->timeout(30);

        return $managedPayments
            ? $client->withHeaders(['Stripe-Version' => StripeManagedPayments::API_VERSION])
            : $client;
    }

    private function answer(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $status], $status);
    }

    private function errorMessage(\Throwable $e): string
    {
        if ($e instanceof RequestException) {
            return (string) ($e->response->json('error.message') ?? $e->getMessage());
        }

        return $e->getMessage();
    }
}
