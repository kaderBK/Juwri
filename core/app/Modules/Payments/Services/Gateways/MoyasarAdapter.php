<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayCurrencies;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 4 item 18: Moyasar (moyasar.com, KSA — mada, Visa/Mastercard, Apple Pay) over its REST API
 * (https://docs.moyasar.com/api), HTTP Basic auth with the secret key as the user name.
 *
 * initiate: POST /v1/invoices — amount in halalas (the smallest unit), currency, description, `success_url` / `back_url`
 * (the return names our transaction), `callback_url` (Moyasar's server notification), metadata.transaction_id. The
 * invoice id is the transaction's provider reference until it is paid; the buyer goes to the invoice `url` (Moyasar's
 * hosted page: card details and 3-D Secure never touch this app).
 * handleReturn: nothing in the request is trusted. The pending transaction named by `txn` is loaded first, then its
 * invoice is read back with THAT transaction's key (the store's for store payments, the platform's otherwise): it must
 * be our invoice (id + metadata), `paid`, for exactly our amount (halalas) and currency, with a `paid` payment. It settles
 * once (claimed) under the PAYMENT id, which the refund needs.
 * handleWebhook: Moyasar's event (`payment_paid`, …) carries the `secret_token` configured on the webhook; it must equal
 * the account's webhook secret (constant-time), else 400. A test-account event (`live: false`) only while payment
 * sandboxes are allowed. Once per event id (WebhookReceipts: a replayed copy is a 200 no-op); the invoice is re-read and
 * settles under the same checks as the return.
 * refund: POST /v1/payments/{id}/refund with the amount in halalas, through RefundGuard.
 *
 * Currency: SAR only ({@see GatewayCurrencies::FIXED}) — the currency Moyasar's docs
 * document for every account. Test keys (sk_test_…) only while payment sandboxes are allowed ({@see GatewaySandbox}).
 */
class MoyasarAdapter implements GatewayAdapter
{
    public const BASE = 'https://api.moyasar.com/v1';

    public function key(): string
    {
        return 'moyasar';
    }

    public function initiate(Transaction $transaction): Response
    {
        $secret = $this->secret($transaction);
        $currency = Settlement::currency($transaction);
        $amount = GatewayMinorUnits::gatewayMinor($this->key(), $transaction->money('amount'), $currency);
        if ($secret === '' || $amount === null || $amount < 1) {
            app_alert_error(___('This payment method is not configured.'));

            return back();
        }

        $return = route('ipn', ['gateway' => 'moyasar', 'txn' => $transaction->id]);
        try {
            $invoice = $this->client($secret)->post('/invoices', [
                'amount' => $amount,
                'currency' => $currency,
                'description' => mb_substr(Settlement::title($transaction), 0, 255),
                'success_url' => $return,
                'back_url' => Settlement::cancelUrl($transaction),
                'callback_url' => route('webhook', 'moyasar'),
                'metadata' => ['transaction_id' => (string) $transaction->id],
            ])->json();
        } catch (\Throwable $e) {
            PaymentLog::failure('moyasar', $e, ['transaction_id' => $transaction->id]);
            $invoice = null;
        }

        $url = is_array($invoice) ? ($invoice['url'] ?? null) : null;
        $invoiceId = is_array($invoice) ? ($invoice['id'] ?? null) : null;
        if (! is_string($url) || $url === '' || ! is_string($invoiceId) || $invoiceId === '') {
            Log::info('[moyasar] invoice not created: '.json_encode(is_array($invoice) ? ($invoice['message'] ?? $invoice['type'] ?? null) : null));
            app_alert_error(___('Could not start the payment. Please try again.'));

            return redirect(Settlement::cancelUrl($transaction));
        }

        $details = (array) ($transaction->details ?? []);
        $details['moyasar_invoice_id'] = $invoiceId;
        $transaction->forceFill(['details' => $details, 'payment_id' => $invoiceId])->save();

        return redirect()->away($url);
    }

    public function handleReturn(Request $request): Response
    {
        $transaction = $this->pendingTransaction($request->get('txn'));
        $invoiceId = $transaction !== null ? (string) data_get($transaction->details, 'moyasar_invoice_id', '') : '';
        if ($transaction === null || $invoiceId === '') {
            return Settlement::invalid();
        }
        $secret = $this->secret($transaction);
        if ($secret === '') {
            return Settlement::failed();
        }

        $invoice = $this->fetchInvoice($invoiceId, $secret);
        $paymentId = $this->paidPayment($invoice, $invoiceId, $transaction);
        if ($paymentId === null) {
            // Not paid (yet): a failed card goes back to retry; an invoice still open waits for the notification.
            return strtolower((string) data_get($invoice, 'status')) === 'initiated'
                ? Settlement::processingForBuyer($transaction)
                : Settlement::failed();
        }

        return Settlement::settleForBuyer($transaction, 'moyasar', $paymentId, claim: true)
            ?? Settlement::completeForBuyer($transaction->fresh() ?? $transaction);
    }

    public function handleWebhook(Request $request): Response
    {
        $event = json_decode($request->getContent(), true);
        $invoiceId = is_array($event) ? (string) data_get($event, 'data.invoice_id', '') : '';
        $transaction = $invoiceId !== '' ? $this->transactionOfInvoice($invoiceId, (string) data_get($event, 'data.id', '')) : null;
        $expected = $transaction !== null ? $this->webhookSecret($transaction) : '';
        $token = is_array($event) ? data_get($event, 'secret_token') : null;
        if ($transaction === null || $expected === '' || ! is_string($token) || ! hash_equals($expected, $token)) {
            PaymentLog::webhookRejected('moyasar', $transaction === null ? 'unknown invoice' : 'invalid secret token');

            return $this->answer(400, 'Invalid Moyasar notification.');
        }
        if (! GatewaySandbox::allowsEvent('moyasar', data_get($event, 'live') !== false)) {
            PaymentLog::webhookRejected('moyasar', 'test-mode event while sandboxes are off');

            return $this->answer(400, 'Test-mode events are not accepted.');
        }

        return WebhookReceipts::once('moyasar', WebhookReceipts::key(data_get($event, 'id'), data_get($event, 'type')), function () use ($event, $transaction, $invoiceId) {
            if (data_get($event, 'type') !== 'payment_paid') {
                return $this->answer(200, 'ignored'); // payment_failed (declined card), refunds, …: nothing to settle
            }
            if ($transaction->status !== null) {
                return $this->answer(200, 'already settled');
            }
            $paymentId = $this->paidPayment($this->fetchInvoice($invoiceId, $this->secret($transaction)), $invoiceId, $transaction);
            if ($paymentId === null) {
                return $this->answer(200, 'not settled');
            }
            try {
                Settlement::settle($transaction, 'moyasar', $paymentId, claim: true);
            } catch (\Throwable $e) {
                PaymentLog::settlementFailed('moyasar', $e);

                return $this->answer(500, 'settlement failed'); // released: Moyasar's retry tries again
            }

            return $this->answer(200, 'successful');
        }, fn () => $this->answer(200, 'duplicate'));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    /**
     * POST /v1/payments/{id}/refund (amount in halalas) with the transaction's own key, through RefundGuard. Registering
     * Moyasar for automatic store refunds (config refunds.adapters) stays the owner's decision (2026-09-27).
     */
    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        $own = Settlement::currency($transaction);

        return RefundGuard::run($transaction, $amount, $currency, $this->key(), GatewayMinorUnits::decimals($own), function (Money $money) use ($transaction) {
            $secret = $this->secret($transaction);
            $paymentId = (string) $transaction->payment_id;
            $minor = GatewayMinorUnits::gatewayMinor($this->key(), $money);
            if ($secret === '' || $paymentId === '' || str_starts_with($paymentId, 'inv_') || $minor === null) {
                return ['ok' => false, 'reference' => null, 'message' => 'No Moyasar payment to refund.'];
            }
            try {
                $refund = $this->client($secret)->post('/payments/'.rawurlencode($paymentId).'/refund', ['amount' => $minor]);
            } catch (ConnectionException $e) {
                throw $e; // no answer: outcome unknown — RefundGuard keeps the reservation
            }
            if ($refund->successful() && in_array(strtolower((string) $refund->json('status')), ['refunded', 'paid'], true)
                && (int) $refund->json('refunded') >= $minor) {
                return ['ok' => true, 'reference' => (string) $refund->json('id'), 'message' => null];
            }

            return ['ok' => false, 'reference' => null, 'message' => (string) ($refund->json('message') ?? 'Moyasar refused the refund.')];
        });
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The id of the paid payment when $invoice is the paid invoice of $transaction — ours (id + metadata), `paid`, exactly
     * our amount in halalas and our currency; null otherwise (failed card, short or foreign invoice).
     */
    private function paidPayment(mixed $invoice, string $invoiceId, Transaction $transaction): ?string
    {
        if (! is_array($invoice)) {
            return null;
        }
        $currency = Settlement::currency($transaction);
        $expected = GatewayMinorUnits::gatewayMinor($this->key(), $transaction->money('amount'), $currency);
        $paid = collect((array) ($invoice['payments'] ?? []))->first(fn ($p) => is_array($p) && ($p['status'] ?? null) === 'paid');
        $amount = $invoice['amount'] ?? null;
        if (($invoice['id'] ?? null) !== $invoiceId
            || (string) data_get($invoice, 'metadata.transaction_id') !== (string) $transaction->id
            || strtolower((string) ($invoice['status'] ?? '')) !== 'paid'
            || ! is_array($paid) || empty($paid['id'])
            || $expected === null || ! is_numeric($amount) || (int) $amount !== $expected
            || strtoupper((string) ($invoice['currency'] ?? '')) !== $currency
            || (isset($paid['amount']) && (int) $paid['amount'] !== $expected)) {
            if (strtolower((string) ($invoice['status'] ?? '')) === 'paid') {
                Log::warning("[moyasar] invoice {$invoiceId} does not pay transaction {$transaction->id}");
            }

            return null;
        }

        return (string) $paid['id'];
    }

    private function fetchInvoice(string $invoiceId, string $secret): mixed
    {
        if ($secret === '') {
            return null;
        }
        try {
            return $this->client($secret)->get('/invoices/'.rawurlencode($invoiceId))->json();
        } catch (\Throwable $e) {
            Log::error('[moyasar] invoice read failed: '.$e->getMessage());

            return null;
        }
    }

    /** The secret key of the account this transaction is paid into: the store's (store payments) or the platform's. */
    private function secret(Transaction $transaction): string
    {
        $secret = Settlement::usesStoreGateway($transaction)
            ? (store_options($transaction->store_id)->restaurant_moyasar_secret_key ?? null)
            : config('settings.moyasar_secret_key');
        $secret = is_string($secret) ? trim($secret) : '';

        return GatewaySandbox::allowsKey('moyasar', $secret) ? $secret : '';
    }

    /** The webhook secret token configured on that account's Moyasar webhook. */
    private function webhookSecret(Transaction $transaction): string
    {
        $secret = Settlement::usesStoreGateway($transaction)
            ? (store_options($transaction->store_id)->restaurant_moyasar_webhook_secret ?? null)
            : config('settings.moyasar_webhook_secret');

        return is_string($secret) ? trim($secret) : '';
    }

    private function pendingTransaction(mixed $reference): ?Transaction
    {
        if (! is_scalar($reference) || ! ctype_digit((string) $reference)) {
            return null;
        }

        return Transaction::query()->whereKey((int) $reference)->whereNull('status')->first();
    }

    /** The transaction of an invoice: pending (payment_id = invoice id) or already settled (payment_id = payment id). */
    private function transactionOfInvoice(string $invoiceId, string $paymentId): ?Transaction
    {
        $pending = Transaction::query()->where('payment_id', $invoiceId)->first();
        if ($pending !== null) {
            return $pending;
        }

        return $paymentId !== '' ? Transaction::query()->where('transaction_gatway', 'moyasar')->where('payment_id', $paymentId)->first() : null;
    }

    private function client(string $secret): PendingRequest
    {
        return Http::baseUrl(self::BASE)->withBasicAuth($secret, '')->asJson()->acceptJson()->timeout(30);
    }

    private function answer(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $status], $status);
    }
}
