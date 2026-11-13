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
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayCurrencies;
use Juwri\Modules\Payments\Services\GatewayRounding;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Razorpay Standard Checkout (https://razorpay.com/docs/api/orders/,
 * https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/).
 *
 * initiate: create an Orders API order (auto-capture) and render our payment
 * page, whose checkout.js form posts `razorpay_order_id`, `razorpay_payment_id`
 * and `razorpay_signature` back to `ipn/razorpay`. The return settles when the
 * signature — hex HMAC-SHA256 of "<order_id>|<payment_id>" with the key secret —
 * matches. The `webhook/razorpay` notification (`payment.captured` /
 * `order.paid`, header X-Razorpay-Signature = hex HMAC-SHA256 of the raw body
 * with the webhook secret) settles a payment whose buyer never came back.
 *
 * Keys: store orders use the store's own keys (BYOK), everything else the
 * platform keys. No subscriptions (monthly add-ons are refused before this for
 * gateways other than Stripe and PayPal).
 */
class RazorpayAdapter implements GatewayAdapter
{
    public const API = 'https://api.razorpay.com/v1';

    public function key(): string
    {
        return 'razorpay';
    }

    public function initiate(Transaction $transaction): Response
    {
        try {
            [$keyId, $secret] = $this->keys($transaction);
            if ($keyId === '' || $secret === '') {
                throw new RuntimeException(___('This payment method is not configured.'));
            }

            $store = Settlement::store($transaction);
            $currency = $this->currency($transaction);
            if ($store !== null) {
                // The payment page shows the total in the store's own currency.
                Settlement::useStoreCurrency($transaction);
            }
            // 3-decimal currencies in whole tens of fils: an amount between steps is charged ROUNDED DOWN (KWD 12.345
            // → 12340) and the difference recorded for the books (GatewayRounding, owner decision). before: fromMoney()
            // rounded half-up (12350); then (earlier today) refused.
            $amount = GatewayRounding::chargeMinor($this->key(), $transaction->money('amount'), $currency);
            if ($amount === null) {
                throw new RuntimeException((string) GatewayCurrencies::refusal($this->key(), $transaction));
            }
            GatewayRounding::record($transaction, $this->key(), $currency);
            $receipt = (string) ($store !== null ? $transaction->product_id : $transaction->id);

            $order = $this->client($keyId, $secret)->post('/orders', [
                'amount' => $amount,
                'currency' => $currency,
                'receipt' => $receipt,
                'payment_capture' => 1,
            ])->throw()->json();

            $transaction->update(['payment_id' => $order['id']]);

            $buyer = request()->user();
            $color = (string) ($store->color ?? config('settings.theme_color'));

            return response()->view('owner.billing.gateways.razorpay', [
                'details' => [
                    'key' => $keyId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'name' => (string) ($store->title ?? config('settings.site_title')),
                    'image' => '',
                    'description' => Settlement::title($transaction),
                    'order_id' => $order['id'],
                    'buttontext' => ___('Pay Now'),
                    'prefill.name' => (string) ($buyer->name ?? $transaction->details->customer_name ?? ''),
                    'prefill.email' => $store !== null ? '' : (string) ($buyer->email ?? ''),
                    'theme.color' => $color,
                ],
                'transaction' => $transaction,
                'cancel_url' => Settlement::cancelUrl($transaction),
                'color' => $color,
            ]);
        } catch (\Throwable $e) {
            PaymentLog::failure('razorpay', $e, ['transaction_id' => $transaction->id]);
            app_alert_error($this->errorMessage($e));

            return back()->withInput();
        }
    }

    public function handleReturn(Request $request): Response
    {
        $orderId = (string) $request->input('razorpay_order_id', '');
        $paymentId = (string) $request->input('razorpay_payment_id', '');
        $transaction = $orderId === '' ? null : Settlement::pending(['payment_id' => $orderId]);
        if ($transaction === null) {
            return Settlement::invalid();
        }

        try {
            [, $secret] = $this->keys($transaction);
            $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, $secret);
            if ($secret === '' || $paymentId === '' || ! hash_equals($expected, (string) $request->input('razorpay_signature', ''))) {
                return Settlement::failed();
            }

            // J25-7: credit durable via the outbox, same answer to the buyer.
            return Settlement::settleForBuyer($transaction, 'razorpay', $paymentId);
        } catch (\Throwable $e) {
            PaymentLog::settlementFailed('razorpay', $e);

            return Settlement::failed();
        }
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $event = json_decode($payload, true);
        if (! is_array($event)) {
            PaymentLog::webhookRejected('razorpay', 'invalid payload');

            return $this->answer(400, 'Invalid payload.');
        }

        $payment = $event['payload']['payment']['entity'] ?? [];
        $orderId = (string) ($payment['order_id'] ?? $event['payload']['order']['entity']['id'] ?? '');
        $transaction = $orderId === '' ? null : Settlement::pending(['payment_id' => $orderId]);

        // The body only picks which secret to check against (settled rows too, so a
        // redelivery verifies and is acknowledged); nothing is trusted before the check.
        $known = $transaction ?? (! empty($payment['id']) ? Transaction::query()->where('payment_id', (string) $payment['id'])->first() : null);
        $secret = $this->webhookSecret($known);
        if ($secret === '') {
            return $this->answer(200, 'webhook not configured');
        }
        $signature = (string) $request->header('X-Razorpay-Signature', '');
        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            PaymentLog::webhookRejected('razorpay', 'invalid signature');

            return $this->answer(400, 'Invalid Razorpay signature.');
        }

        // J25-7: once per verified event (WebhookReceipts): a re-delivered or concurrent copy is a 200 no-op.
        return WebhookReceipts::once('razorpay', WebhookReceipts::key($event['event'] ?? null, $payment['id'] ?? ($orderId !== '' ? $orderId : null), WebhookReceipts::fingerprint($request)), function () use ($transaction, $event, $payment) {
            if ($transaction === null || ! in_array($event['event'] ?? null, ['payment.captured', 'order.paid'], true)) {
                return $this->answer(200, 'successful');
            }

            $currency = $this->currency($transaction);
            if (($payment['status'] ?? null) !== 'captured'
                // The amount we asked for (the rounded-down step for KWD 12.345: 12340) — before: the half-up 12350.
                || (int) ($payment['amount'] ?? -1) !== GatewayRounding::chargeMinor($this->key(), $transaction->money('amount'), $currency)
                || strtoupper((string) ($payment['currency'] ?? '')) !== $currency) {
                Log::warning("[razorpay] webhook payment does not match transaction {$transaction->id}");

                return $this->answer(200, 'ignored');
            }

            try {
                Settlement::settle($transaction, 'razorpay', (string) $payment['id']); // J25-7: credit durable via the outbox
            } catch (\Throwable $e) {
                PaymentLog::settlementFailed('razorpay', $e);

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
                [$keyId, $secret] = $this->keys($transaction);
                $refund = $this->client($keyId, $secret)
                    ->post('/payments/'.rawurlencode((string) $transaction->payment_id).'/refund', [
                        'amount' => GatewayMinorUnits::fromMoney($money),
                    ])->throw()->json();

                return ['ok' => true, 'reference' => $refund['id'] ?? null, 'message' => null];
            } catch (ConnectionException $e) {
                throw $e; // no answer: outcome unknown — RefundGuard keeps the reservation (bug batch 6)
            } catch (\Throwable $e) {
                return ['ok' => false, 'reference' => null, 'message' => $this->errorMessage($e)];
            }
        }, tenFils: true);
    }

    /* ------------------------------------------------------------ internals */

    /** @return array{0:string, 1:string} key id, key secret */
    private function keys(Transaction $transaction): array
    {
        if (Settlement::usesStoreGateway($transaction)) {
            $options = store_options($transaction->store_id);
            $keys = [(string) ($options->restaurant_razorpay_api_key ?? ''), (string) ($options->restaurant_razorpay_secret_key ?? '')];
        } else {
            $keys = [(string) config('settings.razorpay_api_key', ''), (string) config('settings.razorpay_secret_key', '')];
        }

        // Lane D: a test key id (rzp_test_…) only while payment sandboxes are allowed.
        return GatewaySandbox::allowsKey('razorpay', $keys[0]) ? $keys : ['', ''];
    }

    private function webhookSecret(?Transaction $transaction): string
    {
        // Lane D: the webhook of a test account (rzp_test_ key id) is refused while payment sandboxes are off.
        $keyId = $transaction !== null && Settlement::usesStoreGateway($transaction)
            ? (string) (store_options($transaction->store_id, 'restaurant_razorpay_api_key') ?? '')
            : (string) config('settings.razorpay_api_key', '');
        if (! GatewaySandbox::allowsKey('razorpay', $keyId)) {
            return '';
        }
        if ($transaction !== null && Settlement::usesStoreGateway($transaction)) {
            return (string) (store_options($transaction->store_id, 'restaurant_razorpay_webhook_secret') ?? '');
        }

        return (string) config('settings.razorpay_webhook_secret', '');
    }

    /** The transaction's own currency (gateway-currency bug fix: never the store's/platform's current one). */
    private function currency(Transaction $transaction): string
    {
        return Settlement::currency($transaction, 'INR');
    }

    private function client(string $keyId, string $secret): PendingRequest
    {
        return Http::baseUrl(self::API)->withBasicAuth($keyId, $secret)->asJson()->acceptJson()->timeout(30);
    }

    private function answer(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $status], $status);
    }

    private function errorMessage(\Throwable $e): string
    {
        if ($e instanceof RequestException) {
            return (string) ($e->response->json('error.description') ?? $e->getMessage());
        }

        return $e->getMessage();
    }
}
