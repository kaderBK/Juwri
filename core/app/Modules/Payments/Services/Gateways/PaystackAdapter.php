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
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paystack Inline (https://paystack.com/docs/payments/accept-payments/,
 * https://paystack.com/docs/api/transaction/#verify).
 *
 * initiate renders our payment page with the Inline widget (public key,
 * e-mail, amount in the currency's subunit, our transaction id as the
 * reference); the widget posts `reference` to `ipn/paystack`. The return asks
 * Paystack for the transaction (secret key) and settles only a `success` whose
 * amount and currency match ours — the widget's amount is set in the browser,
 * so the server check is what stops an edited, smaller charge. The
 * `webhook/paystack` event `charge.success` (header x-paystack-signature = hex
 * HMAC-SHA512 of the raw body with the secret key) settles a payment whose buyer
 * never came back, with the same amount check.
 *
 * Keys: store orders use the store's own keys (BYOK), everything else the
 * platform keys.
 */
class PaystackAdapter implements GatewayAdapter
{
    public const API = 'https://api.paystack.co';

    public function key(): string
    {
        return 'paystack';
    }

    public function initiate(Transaction $transaction): Response
    {
        $store = Settlement::store($transaction);
        $buyer = request()->user();
        $email = $store === null && $buyer !== null ? (string) $buyer->email : (string) request()->input('email', '');

        // Store orders (and guests paying anything else) give their e-mail on the checkout form.
        if ($store !== null || $buyer === null) {
            $validator = Validator::make(['email' => $email], ['email' => ['required', 'email']]);
            if ($validator->fails()) {
                app_alert_error($validator->errors()->first());

                return back()->withInput();
            }
        }

        $currency = $this->currency($transaction);
        if ($store !== null) {
            Settlement::useStoreCurrency($transaction);
        }
        $title = Settlement::title($transaction);

        return response()->view('owner.billing.gateways.paystack', [
            'details' => [
                'key' => $this->publicKey($transaction),
                'email' => $email,
                'currency' => $currency,
                'amount' => GatewayMinorUnits::fromMoney($transaction->money('amount'), $currency),
                'ref' => (string) $transaction->id,
                'title' => $title,
                'description' => $title,
            ],
            'transaction' => $transaction,
            'color' => (string) ($store->color ?? config('settings.theme_color')),
            'cancel_url' => Settlement::cancelUrl($transaction),
        ]);
    }

    public function handleReturn(Request $request): Response
    {
        $reference = (string) $request->input('reference', '');
        $transaction = $reference === '' ? null : Settlement::pending(['id' => $reference], withoutPaymentId: true);
        if ($transaction === null) {
            return Settlement::invalid();
        }

        try {
            $charge = $this->client($this->secretKey($transaction))
                ->get('/transaction/verify/'.rawurlencode($reference))->json('data');
        } catch (\Throwable $e) {
            Log::error('[paystack] verify failed: '.$e->getMessage());
            $charge = null;
        }

        if (! is_array($charge)) {
            return Settlement::failed();
        }
        if (($charge['status'] ?? null) !== 'success') {
            $reason = (string) ($charge['gateway_response'] ?? '');
            Log::info('Paystack Payment failed: '.$reason);

            return Settlement::failed($reason);
        }
        if (! $this->chargeMatches($charge, $transaction)) {
            return Settlement::failed();
        }

        try {
            // J25-7: credit durable via the outbox, same answer to the buyer.
            return Settlement::settleForBuyer($transaction, 'paystack', null);
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('paystack', $e);

            return Settlement::failed();
        }
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $event = json_decode($payload, true);
        if (! is_array($event)) {
            PaymentLog::webhookRejected('paystack', 'invalid payload');

            return $this->answer(400, 'Invalid payload.');
        }

        $charge = is_array($event['data'] ?? null) ? $event['data'] : [];
        $reference = (string) ($charge['reference'] ?? '');
        $transaction = $reference === '' ? null : Settlement::pending(['id' => $reference], withoutPaymentId: true);

        // The body only picks which secret to check against (settled rows too, so a
        // redelivery verifies and is acknowledged); nothing is trusted before the check.
        $known = $transaction ?? (ctype_digit($reference) ? Transaction::find((int) $reference) : null);
        $secret = $known !== null ? $this->secretKey($known) : GatewaySandbox::key('paystack', (string) config('settings.paystack_secret_key', ''));
        $signature = (string) $request->header('x-paystack-signature', '');
        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha512', $payload, $secret), $signature)) {
            PaymentLog::webhookRejected('paystack', 'invalid signature');

            return $this->answer(400, 'Invalid Paystack signature.');
        }

        // J25-7: once per verified event (WebhookReceipts): a re-delivered or concurrent copy is a 200 no-op.
        return WebhookReceipts::once('paystack', WebhookReceipts::key($event['event'] ?? null, $charge['id'] ?? ($reference !== '' ? $reference : null), WebhookReceipts::fingerprint($request)), function () use ($transaction, $event, $charge) {
            if ($transaction === null || ($event['event'] ?? null) !== 'charge.success'
                || ($charge['status'] ?? null) !== 'success' || ! $this->chargeMatches($charge, $transaction)) {
                return $this->answer(200, 'successful');
            }

            try {
                Settlement::settle($transaction, 'paystack'); // J25-7: credit durable via the outbox
            } catch (\Throwable $e) {
                PaymentLog::settlementFailed('paystack', $e);

                return $this->answer(500, 'settlement failed');
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
        $own = $this->currency($transaction);

        return RefundGuard::run($transaction, $amount, $currency, $this->key(), GatewayMinorUnits::decimals($own), function (Money $money) use ($transaction) {
            try {
                $refund = $this->client($this->secretKey($transaction))->post('/refund', [
                    'transaction' => (string) $transaction->id,
                    'amount' => GatewayMinorUnits::fromMoney($money),
                ])->throw()->json('data');

                return ['ok' => true, 'reference' => isset($refund['id']) ? (string) $refund['id'] : null, 'message' => null];
            } catch (ConnectionException $e) {
                throw $e; // no answer: outcome unknown — RefundGuard keeps the reservation (bug batch 6)
            } catch (\Throwable $e) {
                return ['ok' => false, 'reference' => null, 'message' => $e instanceof RequestException
                    ? (string) ($e->response->json('message') ?? $e->getMessage())
                    : $e->getMessage()];
            }
        }, tenFils: true);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The charge is for our amount (subunits) in our currency.
     *
     * @param  array<string, mixed>  $charge
     */
    private function chargeMatches(array $charge, Transaction $transaction): bool
    {
        $expectedCurrency = strtoupper((string) ($transaction->currency_code ?? ''));
        $paidCurrency = strtoupper((string) ($charge['currency'] ?? ''));
        $currency = $expectedCurrency !== '' ? $expectedCurrency : $paidCurrency;
        $paid = (int) ($charge['amount'] ?? 0) / (10 ** GatewayMinorUnits::decimals($currency));

        if (abs($paid - (float) $transaction->amount) >= 0.01 || ($expectedCurrency !== '' && $paidCurrency !== $expectedCurrency)) {
            Log::warning("Paystack amount/currency mismatch on transaction {$transaction->id}: paid {$paid} {$paidCurrency} expected {$transaction->amount} {$expectedCurrency}");

            return false;
        }

        return true;
    }

    private function publicKey(Transaction $transaction): string
    {
        return Settlement::usesStoreGateway($transaction)
            ? (string) (store_options($transaction->store_id, 'restaurant_paystack_public_key') ?? '')
            : (string) config('settings.paystack_public_key', '');
    }

    private function secretKey(Transaction $transaction): string
    {
        // Lane D: a test key (sk_test_…) only while payment sandboxes are allowed.
        return GatewaySandbox::key('paystack', Settlement::usesStoreGateway($transaction)
            ? (string) (store_options($transaction->store_id, 'restaurant_paystack_secret_key') ?? '')
            : (string) config('settings.paystack_secret_key', ''));
    }

    /** The transaction's own currency (gateway-currency bug fix: never the store's/platform's current one). */
    private function currency(Transaction $transaction): string
    {
        return Settlement::currency($transaction, 'NGN');
    }

    private function client(string $secret): PendingRequest
    {
        return Http::baseUrl(self::API)->withToken($secret)->acceptJson()->timeout(30);
    }

    private function answer(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $status], $status);
    }
}
