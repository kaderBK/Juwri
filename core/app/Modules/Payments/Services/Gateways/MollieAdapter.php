<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayCurrencies;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\AccountPages;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Mollie\Api\Exceptions\NetworkRequestException;
use Mollie\Api\Exceptions\ValidationException;
use Mollie\Api\MollieApiClient;
use Mollie\Laravel\Facades\Mollie;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mollie Payments API v2 through the official client
 * (https://docs.mollie.com/reference/create-payment,
 * https://docs.mollie.com/reference/webhooks).
 *
 * initiate: create a payment (redirect back to `ipn/mollie?order_id=<tx>`, plus
 * the order's tracking token for store orders; webhook `webhook/mollie` unless
 * switched off), keep its `tr_…` id as the transaction's payment_id and send
 * the buyer to the checkout link. Mollie's webhook only carries the payment id,
 * so the payment is always fetched back with our key; `paid` for our amount
 * settles through a conditional claim, so the webhook and the buyer's return
 * settle once between them. The webhook always answers 200 (Mollie retries
 * anything else) and records when it last heard from Mollie.
 *
 * Keys: store orders use the store's API key (BYOK), everything else the
 * platform's; test/live follows the key's prefix.
 */
class MollieAdapter implements GatewayAdapter
{
    public function key(): string
    {
        return 'mollie';
    }

    /**
     * Where Mollie should post status changes: none when switched off in the
     * gateway settings, the override when one is set, none on a local machine
     * Mollie cannot reach, otherwise our webhook route.
     */
    public function webhookUrl(): ?string
    {
        if ((string) config('settings.mollie_webhook_enabled', '1') !== '1') {
            return null;
        }
        $override = trim((string) config('settings.mollie_webhook_url', ''));
        if ($override !== '') {
            return $override;
        }

        return app()->environment('local') ? null : route('webhook', 'mollie');
    }

    public function initiate(Transaction $transaction): Response
    {
        try {
            $client = $this->client($transaction);
            $currency = Settlement::currency($transaction, 'EUR');
            $payload = [
                'description' => Settlement::title($transaction),
                'amount' => ['currency' => $currency, 'value' => $this->chargeableValue($transaction, $currency)],
                'redirectUrl' => $this->returnUrl($transaction),
                'metadata' => ['transaction_id' => (string) $transaction->id],
            ];
            $webhook = $this->webhookUrl();
            if ($webhook !== null) {
                $payload['webhookUrl'] = $webhook;
            }

            try {
                $payment = $client->payments->create($payload);
            } catch (ValidationException $e) {
                if (! isset($payload['webhookUrl'])) {
                    throw $e;
                }
                // Mollie refuses a webhook it cannot reach; tell the operator and fall back to the return.
                AlertDispatcher::send('payments.mollie_webhook_rejected', 'Mollie rejected the webhook URL '.$payload['webhookUrl']
                    .'; payments now settle only when the buyer returns. '.$e->getMessage());
                unset($payload['webhookUrl']);
                $payment = $client->payments->create($payload);
            }

            $transaction->update(['payment_id' => (string) $payment->id]);

            return redirect()->away((string) $payment->getCheckoutUrl());
        } catch (\Throwable $e) {
            Log::warning("[mollie] create payment failed for transaction {$transaction->id}: ".$e->getMessage());
            app_alert_error($e->getMessage());

            return back()->withInput();
        }
    }

    public function handleReturn(Request $request): Response
    {
        try {
            $id = (string) $request->input('order_id', '');
            $transaction = ctype_digit($id) ? Settlement::pending(['id' => $id]) : null;
            if ($transaction === null || (string) $transaction->payment_id === '') {
                return $this->alreadyHandled($id, (string) $request->input('t', ''));
            }

            $payment = $this->client($transaction)->payments->get((string) $transaction->payment_id);
            if ($payment->status !== 'paid' || ! $this->amountMatches($payment, $transaction)) {
                if (in_array($payment->status, ['open', 'pending', 'authorized'], true)) {
                    return Settlement::processingForBuyer($transaction);
                }
                app_alert_error(___('Payment failed, please try again.'));

                return redirect()->to(Settlement::cancelUrl($transaction));
            }

            // J25-7: claim + durable credit; another confirmation that already settled it → "already handled".
            return Settlement::settleForBuyer($transaction, 'mollie', (string) $payment->id, claim: true)
                ?? $this->alreadyHandled($id, (string) $request->input('t', ''));
        } catch (\Throwable $e) {
            Log::warning('[mollie] ipn failed: '.$e->getMessage());
            app_alert_error(___('Payment failed, please try again.'));

            return back();
        }
    }

    public function handleWebhook(Request $request): Response
    {
        $paymentId = (string) $request->input('id', '');
        if ($paymentId === '') {
            return response('ok');
        }

        $transaction = Settlement::pending(['payment_id' => $paymentId]);
        if ($transaction === null) {
            $this->recordWebhookSeen('ignored');

            return response('ok');
        }
        $store = Settlement::usesStoreGateway($transaction) ? (int) $transaction->store_id : null;

        try {
            $payment = $this->client($transaction)->payments->get($paymentId);
        } catch (\Throwable $e) {
            Log::warning("[mollie] webhook re-fetch failed for {$paymentId}: ".$e->getMessage());
            $this->recordWebhookSeen('error', $store);

            return response('ok');
        }

        if ($payment->status !== 'paid' || ! $this->amountMatches($payment, $transaction)) {
            $this->recordWebhookSeen('not_paid:'.$payment->status, $store);

            return response('ok');
        }

        // J25-7: the notification carries no event id — the verified event is "mollie confirmed this payment paid"; a repeated or concurrent confirmation is a no-op (WebhookReceipts).
        return WebhookReceipts::once('mollie', WebhookReceipts::key($paymentId, 'paid'), function () use ($transaction, $paymentId, $store) {
            // J25-7: claim + credit message in one DB transaction; a failing credit is retried by the outbox relay.
            try {
                $settled = Settlement::settle($transaction, 'mollie', $paymentId, claim: true);
            } catch (\Throwable $e) {
                $this->recordWebhookSeen('settled', $store);
                PaymentLog::settlementFailed('mollie', $e);

                return response('ok');
            }
            $this->recordWebhookSeen($settled ? 'settled' : 'already_settled', $store);

            return response('ok');
        }, fn () => response('ok'));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        $own = Settlement::currency($transaction, 'EUR');

        // At Mollie's own decimals (ISK 0) and charging step (HUF/TWD whole) — before: decimals($own), ISK refunds sent
        // as "100.00" and HUF 10.50 refunded as is.
        return RefundGuard::run($transaction, $amount, $currency, $this->key(), GatewayMinorUnits::gatewayPrecision($this->key(), $own), function (Money $money, string $idempotencyKey) use ($transaction) {
            $client = null;
            try {
                // P6-80: Idempotency-Key = the ledger entry id (not the SDK's random per-request one) — a retry after a
                // timeout gets the first refund back. Reset after: the client is a shared singleton.
                $client = $this->client($transaction)->setIdempotencyKey('refund-'.$idempotencyKey);
                $refund = $client->paymentRefunds->createForId((string) $transaction->payment_id, [
                    'amount' => ['currency' => $money->currency(), 'value' => (string) GatewayMinorUnits::gatewayMajorString($this->key(), $money)],
                    'description' => 'Refund '.$transaction->id,
                ]);

                return ['ok' => true, 'reference' => (string) $refund->id, 'message' => null];
            } catch (NetworkRequestException|ConnectionException $e) {
                // No answer (timeout, dropped connection, 408): the outcome is unknown — RefundGuard keeps the
                // reservation pending (bug batch 8, like the Http-client adapters). Explicit API errors stay refusals.
                throw $e;
            } catch (\Throwable $e) {
                return ['ok' => false, 'reference' => null, 'message' => $e->getMessage()];
            } finally {
                $client?->resetIdempotencyKey();
            }
        }, idempotent: true);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The shared client, authenticated with this transaction's key for this
     * call (a long-lived worker never reuses another store's key).
     */
    private function client(Transaction $transaction): MollieApiClient
    {
        $key = Settlement::usesStoreGateway($transaction)
            ? trim((string) (store_options($transaction->store_id, 'restaurant_mollie_api_key') ?? ''))
            : trim((string) config('settings.mollie_api_key', ''));
        // Lane D: a test key (test_…) only while payment sandboxes are allowed.
        $key = GatewaySandbox::key('mollie', $key);
        if ($key === '') {
            throw new RuntimeException(___('This payment method is not configured.'));
        }

        /** @var MollieApiClient $client */
        $client = Mollie::api();
        $client->setApiKey($key);

        return $client;
    }

    private function returnUrl(Transaction $transaction): string
    {
        $url = route('ipn', 'mollie').'?order_id='.$transaction->id;
        if (Settlement::isOrder($transaction)) {
            $token = Order::query()->whereKey($transaction->product_id)->value('track_token');
            if (is_string($token) && $token !== '') {
                $url .= '&t='.rawurlencode($token);
            }
        }

        return $url;
    }

    /**
     * `amount.value` at Mollie's own decimals (ISK whole: "500", not "500.00"), or the payer-facing refusal when Mollie
     * cannot charge it exactly (HUF 1500.50 — PayPal payments are rounded up by Mollie). Checked again here, not only in
     * pay(). before: majorStringFromMoney() — ISK "500.00", HUF "1500.50".
     */
    private function chargeableValue(Transaction $transaction, string $currency): string
    {
        $value = GatewayMinorUnits::gatewayMajorString($this->key(), $transaction->money('amount'), $currency);
        if ($value === null) {
            throw new RuntimeException((string) GatewayCurrencies::refusal($this->key(), $transaction));
        }

        return $value;
    }

    /**
     * The paid amount is ours, in the transaction's currency (Mollie payments are created server-side; this guards a
     * mixed-up id and a payment created in another currency).
     */
    private function amountMatches(object $payment, Transaction $transaction): bool
    {
        $value = $payment->amount->value ?? null;
        $paid = strtoupper((string) ($payment->amount->currency ?? ''));
        $expected = Settlement::currency($transaction, 'EUR');
        // Exact (GatewayMinorUnits::paidMatches) — before: within ±0.01, and by float a whole cent short settled.
        if (! GatewayMinorUnits::paidMatches('mollie', $value, $transaction->money('amount'), $expected) || ($paid !== '' && $paid !== $expected)) {
            $value = is_scalar($value) ? (string) $value : '-';
            Log::warning("mollie amount/currency mismatch on transaction {$transaction->id}: paid {$value} {$paid} expected {$transaction->amount} {$expected}");

            return false;
        }

        return true;
    }

    /**
     * The buyer is back but nothing is pending (normally: the webhook settled
     * first). Orders go to tracking, a settled plan payment gets its success
     * notice, anything else is an invalid transaction.
     */
    private function alreadyHandled(string $id, string $trackToken): Response
    {
        if ($trackToken !== '' && Order::query()->where('track_token', $trackToken)->where('is_paid', 1)->exists()) {
            return redirect()->route('store.orderTrack', $trackToken);
        }
        if (ctype_digit($id) && Transaction::query()->whereKey((int) $id)->where('transaction_gatway', 'mollie')
            ->whereIn('status', [Transaction::STATUS_SUCCESS, Transaction::STATUS_CONSUMED])->exists()) {
            app_alert_success(___('Payment successful'));

            return redirect()->to(AccountPages::url());
        }

        return Settlement::invalid();
    }

    /** Heartbeat for the gateway settings screens ("last webhook received"); never throws. */
    private function recordWebhookSeen(string $status, ?int $storeId = null): void
    {
        try {
            $now = now()->format('Y-m-d H:i:s');
            PlatformSettings::set('mollie_webhook_last_at', $now);
            PlatformSettings::set('mollie_webhook_last_status', $status);
            if ($storeId !== null) {
                StoreOption::updateStoreOption($storeId, 'restaurant_mollie_webhook_last_at', $now);
                StoreOption::updateStoreOption($storeId, 'restaurant_mollie_webhook_last_status', $status);
            }
        } catch (\Throwable) {
            // A heartbeat must never break a settlement.
        }
    }
}
