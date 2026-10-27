<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\MorPaymentGuard;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\AccountPages;
use Juwri\Modules\Payments\Support\Fulfilment;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * MoR-RUN WI-P1/WI-P2 — Paddle Billing (replaces Paddle Classic).
 *
 * pay():    POST {base}/transactions with an inline (non-catalog) price and
 *           redirect the browser to the hosted checkout URL.
 * webhook(): verify `Paddle-Signature: ts=<unix>;h1=<hex>` over ts:rawBody
 *           with HMAC-SHA256 and dispatch `transaction.completed` into the
 *           shared CheckoutController::processWebhook pipeline (which also
 *           routes add-on transactions to processAddonWebhook).
 */
class PaddleController extends Controller
{
    /** Max accepted webhook timestamp drift, in seconds. */
    private const WEBHOOK_TS_TOLERANCE = 300;

    /** Currencies whose minor unit equals the major unit (no cents). */
    /** Live/sandbox API base URL per `paddle_sandbox_mode`. */
    private static function apiBase(): string
    {
        return gateway_is_sandbox(config('settings.paddle_sandbox_mode'), false)
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';
    }

    /**
     * Open a Paddle Billing transaction and send the buyer to its hosted checkout.
     */
    public static function pay(Transaction $transaction): RedirectResponse
    {
        $title = 'Payment for '.$transaction->product_name.' Plan ('.$transaction->frequency.')';

        /* Paddle Billing amounts are STRINGS in minor units. */
        $amount = (string) self::minorUnits($transaction);

        /* The old Classic `passthrough` — round-trips back in webhook custom_data. */
        $metadata = $transaction->legacyAttributes();

        $item = [
            'quantity' => 1,
            'price' => [
                'description' => $title,
                'name' => (string) $transaction->product_name,
                'unit_price' => [
                    'amount' => $amount,
                    'currency_code' => $transaction->currency_code,
                ],
                'product' => [
                    'name' => (string) $transaction->product_name,
                    'tax_category' => 'saas',
                ],
            ],
        ];

        /* MONTHLY/YEARLY → recurring billing cycle; LIFETIME/one_time → omit. */
        if ($transaction->frequency == 'MONTHLY') {
            $item['price']['billing_cycle'] = ['interval' => 'month', 'frequency' => 1];
        } elseif ($transaction->frequency == 'YEARLY') {
            $item['price']['billing_cycle'] = ['interval' => 'year', 'frequency' => 1];
        }

        $response = Http::withToken(config('settings.paddle_api_key'))
            ->acceptJson()
            ->post(self::apiBase().'/transactions', [
                'items' => [$item],
                'custom_data' => $metadata,
                'collection_mode' => 'automatic',
            ]);

        $checkout_url = $response->json('data.checkout.url');

        if ($response->successful() && ! empty($checkout_url)) {
            /* BL-038: the checkout row is a Paddle checkout — the hourly cleanup keeps it (it may still be paid, and its
               renewals are bound to it) instead of deleting it after 3 hours. */
            $details = (array) ($transaction->details ?? []);
            $details['checkout_gateway'] = 'paddle';
            $details['paddle_transaction'] = (string) $response->json('data.id');
            $transaction->update(['details' => $details]);

            /* Hosted checkout — no gateway form view needed. */
            return redirect()->away($checkout_url);
        }

        $error = $response->json('error.detail')
            ?? $response->json('error.message')
            ?? ___('Payment failed, please try again.');

        Log::info('[paddle] create transaction failed: '.$error);
        app_alert_error($error);

        return redirect()->back()->withInput();
    }

    /**
     * Buyer back from the hosted checkout. Paddle confirms the payment through
     * the webhook; the return only tells the buyer it is on its way.
     */
    public static function ipn(Request $request): RedirectResponse
    {
        app_alert_success(___('Your payment is processing.'));

        return redirect()->to(AccountPages::url());
    }

    /**
     * Paddle notifications: signed `transaction.completed` settles through
     * CheckoutController::processWebhook; refunds, cancels and past-due go to
     * Billing's MorWebhookLifecycleService (through Fulfilment's MoR hook slots); anything else is acknowledged.
     */
    // BL-038: Response, not JsonResponse — a payment settled concurrently answers WebhookReceipts' plain 503 (retry later).
    public static function webhook(Request $request): Response
    {
        if (! self::verifySignature($request)) {
            return self::answer(400, 'Invalid signature verification.');
        }

        $payload = json_decode($request->getContent());

        // J25-7: once per verified event (WebhookReceipts): a re-delivered or concurrent copy is a 200 no-op.
        return WebhookReceipts::once('paddle', WebhookReceipts::key($payload->event_id ?? ($payload->notification_id ?? null), WebhookReceipts::fingerprint($request)), function () use ($payload) {
            $event_type = $payload->event_type ?? null;

            if ($event_type === 'transaction.completed') {
                $data = $payload->data ?? null;
                $metadata = isset($data->custom_data) ? (object) $data->custom_data : null;

                if ($metadata) {
                    /* WI-06d: the custom data must be one of our checkout
                       transactions, and Paddle's paid total must cover it in its
                       currency, before anything is granted or extended. */
                    /* BL-038: a renewal whose checkout row the old cleanup deleted settles against the subscription
                       it renews (MorPaymentGuard::renewalCheckout); a refusal of captured money raises an alert. */
                    $checkout = MorPaymentGuard::checkoutTransaction($metadata)
                        ?? MorPaymentGuard::renewalCheckout('paddle', $metadata, $data->subscription_id ?? null);
                    if ($checkout === null) {
                        Log::warning('[paddle] custom data matches no checkout transaction; not settled');
                        MorPaymentGuard::refused('paddle', $data->id ?? null, 'custom data matches no checkout transaction or subscription');

                        return self::answer(400);
                    }
                    $totals = $data->details->totals ?? null;
                    $paid = $totals->grand_total ?? $totals->total ?? null;
                    $currency = $data->currency_code ?? $totals->currency_code ?? null;
                    if (! MorPaymentGuard::paidCovers($checkout, $paid, $currency, self::minorUnits($checkout))) {
                        Log::warning("[paddle] paid total does not cover checkout transaction {$checkout->id}; not settled");
                        MorPaymentGuard::refused('paddle', $data->id ?? null, 'paid total does not cover the checkout');

                        return self::answer(400);
                    }

                    /* processWebhook routes transaction_method=addon to
                       processAddonWebhook itself and stores the subscription as
                       unique_id 'paddle###<subscription_id>' — same contract as Stripe. */
                    $response = Fulfilment::webhook(
                        'paddle',
                        $metadata,
                        $data->id ?? null,
                        $data->subscription_id ?? null
                    );
                    /* BL-038: a settled checkout row is marked (the cleanup keeps it for every later renewal — before,
                       it was deleted after 3 hours and each renewal refused); a refused settlement raises an alert. */
                    MorPaymentGuard::markSettled($response, $metadata, ! empty($data->subscription_id));
                    MorPaymentGuard::stampSubscription('paddle', $data->id ?? null, $data->subscription_id ?? null);
                    // 503 = the same payment is being settled right now (WebhookReceipts): Paddle retries, no alert.
                    if ($response instanceof Response && $response->getStatusCode() >= 400 && $response->getStatusCode() !== 503) {
                        MorPaymentGuard::refused('paddle', $data->id ?? null, 'the settlement answered '.$response->getStatusCode());
                    }

                    return $response;
                }
                MorPaymentGuard::refused('paddle', $data->id ?? null, 'no custom data');

                return self::answer(400);
            }

            $data = $payload->data ?? null;

            /* M3: refund → reverse the linked Transaction + end entitlement.
               WI-06e: an adjustment only does so when it is an approved refund or
               chargeback covering the whole payment in its currency; credits,
               pending or rejected refunds and partial refunds change nothing here. */
            if ($event_type === 'transaction.refunded') {
                Fulfilment::morRefund('paddle', $data->transaction_id ?? $data->id ?? null);
            } elseif (in_array($event_type, ['adjustment.created', 'adjustment.updated'], true)) {
                $refunded = self::fullyReversedTransaction($data);
                if ($refunded !== null) {
                    Fulfilment::morRefund('paddle', $refunded->payment_id);
                }
            } elseif ($event_type === 'subscription.canceled') {
                /* M3: gateway-side cancel → expire the local sub (no re-activation).
                 *
                 * Deliberately NOT switched to cancelAtPeriodEnd alongside Lemon
                 * Squeezy: Paddle supports both immediate and end-of-period
                 * cancellation and fires this same event for either, so honouring
                 * the local ends_at would hand free time to a merchant who asked to
                 * stop right now. Telling them apart needs the payload's
                 * scheduled_change / canceled_at, which is its own change with its
                 * own fixtures. Lemon Squeezy had no such ambiguity — it fires
                 * `cancelled` and `expired` as two distinct events. */
                Fulfilment::morCancel('paddle', $data->id ?? null);
            } elseif ($event_type === 'subscription.past_due') {
                /* M3: failed renewal → past_due with grace. */
                Fulfilment::morPastDue('paddle', $data->id ?? null);
            }

            /* subscription.created needs no work of its own: the subscription id
               arrives on transaction.completed and processWebhook persists it into
               upgrades.unique_id. Unknown/other events → 200 so Paddle stops retrying. */
            return self::answer(200, 'successful');
        }, fn () => self::answer(200, 'duplicate'));
    }

    /**
     * The settled Paddle payment an adjustment takes back in full, or null.
     * Paddle adjustments are refunds (which need approval), chargebacks,
     * credits and their reversals, full or partial; only an approved refund
     * or chargeback whose total covers the payment in its currency counts.
     */
    private static function fullyReversedTransaction(?object $adjustment): ?Transaction
    {
        $action = (string) ($adjustment->action ?? '');
        $status = (string) ($adjustment->status ?? '');
        $paddleTxnId = $adjustment->transaction_id ?? null;
        if (! in_array($action, ['refund', 'chargeback'], true) || $status !== 'approved' || ! is_string($paddleTxnId) || $paddleTxnId === '') {
            Log::info("[paddle] adjustment ({$action}, {$status}) does not reverse a payment; ignored");

            return null;
        }

        $transaction = Transaction::where('payment_id', $paddleTxnId)
            ->where('transaction_gatway', 'paddle')
            ->where('status', Transaction::STATUS_SUCCESS)
            ->latest('id')
            ->first();
        if ($transaction === null) {
            return null;
        }

        $totals = $adjustment->totals ?? null;
        $total = $totals->total ?? null;
        $currency = strtoupper((string) ($adjustment->currency_code ?? $totals->currency_code ?? ''));
        if (! is_numeric($total) || $currency !== strtoupper((string) $transaction->currency_code)
            || (int) $total < self::minorUnits($transaction)) {
            Log::warning("[paddle] {$action} on {$paddleTxnId} is partial or in another currency ({$total} {$currency}); transaction {$transaction->id} left as is, reconcile by hand");

            return null;
        }

        return $transaction;
    }

    /** The transaction amount in Paddle minor units. */
    private static function minorUnits(Transaction $transaction): int
    {
        // Paddle amounts are integers in the currency's ISO minor unit (JPY ×1, USD ×100, KWD ×1000).
        return (int) round(((float) $transaction->amount) * (10 ** GatewayMinorUnits::decimals((string) $transaction->currency_code)));
    }

    /** JSON answer in the shape Paddle's retries and our tests expect. */
    private static function answer(int $status, ?string $message = null): JsonResponse
    {
        $body = $message === null ? [] : ['message' => $message];
        $body['status'] = $status;

        return response()->json($body, $status);
    }

    /**
     * Verify `Paddle-Signature: ts=<unix>;h1=<hex>` — HMAC-SHA256 over
     * "<ts>:<raw body>" with the webhook secret; reject stale timestamps.
     */
    private static function verifySignature(Request $request): bool
    {
        $secret = config('settings.paddle_webhook_secret');
        $header = $request->header('Paddle-Signature');

        if (empty($secret) || empty($header)) {
            return false;
        }

        $ts = null;
        $h1 = null;
        foreach (explode(';', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 'ts') {
                $ts = $value;
            } elseif ($key === 'h1') {
                $h1 = $value;
            }
        }

        if (empty($ts) || empty($h1) || ! ctype_digit((string) $ts)) {
            return false;
        }

        /* Reject stale/future timestamps (replay hardening). */
        if (abs(time() - (int) $ts) > self::WEBHOOK_TS_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $ts.':'.$request->getContent(), $secret);

        return hash_equals($expected, strtolower((string) $h1));
    }
}
