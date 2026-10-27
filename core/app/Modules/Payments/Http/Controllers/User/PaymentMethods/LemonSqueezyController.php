<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use App\Models\Upgrade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayCurrencies;
use Juwri\Modules\Payments\Services\MorPaymentGuard;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\AccountPages;
use Juwri\Modules\Payments\Support\Fulfilment;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Juwri\Modules\Payments\Support\LemonSqueezyStore;
use Symfony\Component\HttpFoundation\Response;

/**
 * MoR-RUN WI-L1/WI-L2 — Lemon Squeezy (merchant of record) gateway.
 *
 * Checkout: POST /v1/checkouts (JSON:API) with custom_price overriding a
 * pre-created variant; subscription-vs-one-time is a property of the VARIANT,
 * so two configured variant ids serve every plan.
 *
 * Webhook idempotency design (WI-L2): a new subscription purchase fires BOTH
 * order_created and subscription_created. Only order_created settles money
 * (processWebhook, payment_id = order id — deduped on payment_id+gateway).
 * subscription_created never calls processWebhook; it only stamps the
 * captured subscription id ('lemon_squeezy###<sub_id>') onto the user's
 * Upgrade row so renewals/cancellation can reference it. Renewals arrive as
 * subscription_payment_success with a fresh invoice id → processWebhook with
 * that invoice id as payment_id + the subscription id.
 */
class LemonSqueezyController extends Controller
{
    /**
     * Process the payment
     */
    public static function pay(Transaction $transaction)
    {
        $title = 'Payment for '.$transaction->product_name.' Plan ('.$transaction->frequency.')';

        // MC-01: minor units via the single gateway contract, keyed on the
        // transaction's own currency (falling back to platform currency), so
        // three-decimal currencies stop being charged at 1/10th.
        $currency = strtoupper((string) ($transaction->currency_code ?: platform_currency()));
        // Lemon Squeezy sells in none of the 3-decimal currencies (GatewayCurrencies::MAX_CURRENCY_DECIMALS; its list:
        // docs.lemonsqueezy.com/help/payments/currencies) — refused before any call. before: KWD 12.340 sent as 12340.
        $refusal = GatewayCurrencies::refusal('lemon_squeezy', $transaction);
        $custom_price = $refusal === null
            ? GatewayMinorUnits::gatewayMinor('lemon_squeezy', $transaction->money('amount'), $currency)
            : null;
        if ($custom_price === null) {
            app_alert_error((string) ($refusal ?? GatewayCurrencies::refusal('lemon_squeezy', $transaction)));

            return back()->withInput();
        }
        // `custom_price` carries no currency: it is read in the Lemon Squeezy STORE's currency ("The currency you choose
        // for your store … will be used throughout the Lemon Squeezy dashboard, checkout …"). A transaction in another
        // currency would be charged as the same number in the store's currency — refused unless the store's currency
        // (GET /v1/stores/{id}, docs.lemonsqueezy.com/api/stores/the-store-object) is the transaction's.
        // before: sent (a USD 12.50 plan charged EUR 12.50 on a EUR store).
        $storeCurrency = LemonSqueezyStore::currency((string) config('settings.lemon_squeezy_api_key'), (string) config('settings.lemon_squeezy_store_id'));
        if ($storeCurrency !== $currency) {
            Log::warning("[lemon_squeezy] transaction {$transaction->id} is in {$currency}; the Lemon Squeezy store sells in ".($storeCurrency ?? 'an unknown currency').' — payment refused');
            app_alert_error((string) ___('This payment method cannot charge in :currency. Please choose another payment method.', ['currency' => $currency]));

            return back()->withInput();
        }

        /* LS rejects non-string custom values — keep scalars only, stringified. */
        $custom = [];
        foreach ($transaction->legacyAttributes() as $key => $value) {
            if (is_scalar($value)) {
                $custom[$key] = strval($value);
            }
        }

        $variant_id = in_array($transaction->frequency, ['MONTHLY', 'YEARLY'])
            ? config('settings.lemon_squeezy_variant_id')
            : config('settings.lemon_squeezy_variant_id_onetime');

        $body = [
            'data' => [
                'type' => 'checkouts',
                'attributes' => [
                    'custom_price' => $custom_price,
                    'checkout_data' => [
                        'email' => request()->user()->email ?? null,
                        'custom' => $custom,
                    ],
                    'product_options' => [
                        'redirect_url' => route('ipn', 'lemon_squeezy'),
                        'name' => $title,
                    ],
                ],
                'relationships' => [
                    'store' => [
                        'data' => ['type' => 'stores', 'id' => (string) config('settings.lemon_squeezy_store_id')],
                    ],
                    'variant' => [
                        'data' => ['type' => 'variants', 'id' => (string) $variant_id],
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken(config('settings.lemon_squeezy_api_key'))
                ->withHeaders([
                    'Accept' => 'application/vnd.api+json',
                    'Content-Type' => 'application/vnd.api+json',
                ])
                ->post('https://api.lemonsqueezy.com/v1/checkouts', $body);

            $url = $response->json('data.attributes.url');

            if ($response->successful() && ! empty($url)) {
                /* BL-038: the checkout row is a Lemon Squeezy checkout — the hourly cleanup keeps it (it may still be
                   paid, and its renewals are bound to it) instead of deleting it after 3 hours. */
                $details = (array) ($transaction->details ?? []);
                $details['checkout_gateway'] = 'lemon_squeezy';
                $transaction->update(['details' => $details]);

                return redirect()->away($url);
            }

            $message = $response->json('errors.0.detail') ?? 'Lemon Squeezy checkout creation failed.';
            Log::info('[lemon_squeezy] '.$message);
            app_alert_error($message);

            return back()->withInput();
        } catch (\Exception $e) {
            Log::info('[lemon_squeezy] '.$e->getMessage());
            app_alert_error($e->getMessage());

            return back()->withInput();
        }
    }

    /**
     * Handle the IPN (browser return from hosted checkout)
     *
     * @return RedirectResponse
     */
    public static function ipn(Request $request)
    {
        /* Actual crediting happens via webhook; the browser just returns here. */
        app_alert_success(___('Your payment is processing.'));

        return redirect()->to(AccountPages::url());
    }

    /**
     * WI-06d: the custom data must be one of our checkout transactions and
     * the order/invoice total Lemon Squeezy reports (minor units, tax
     * included) must cover it in its currency. BL-038: a renewal whose
     * checkout row the old cleanup deleted is bound to the subscription it
     * renews instead (MorPaymentGuard::renewalCheckout); a refusal raises an
     * alert (money was captured).
     */
    private static function paymentMatches(object $customData, mixed $attributes, mixed $paymentId, mixed $subscriptionId = null): bool
    {
        $checkout = MorPaymentGuard::checkoutTransaction($customData)
            ?? MorPaymentGuard::renewalCheckout('lemon_squeezy', $customData, $subscriptionId);
        if ($checkout === null) {
            Log::warning('[lemon_squeezy] custom data matches no checkout transaction; not settled');
            MorPaymentGuard::refused('lemon_squeezy', $paymentId, 'custom data matches no checkout transaction or subscription');

            return false;
        }
        $currency = MorPaymentGuard::currency($checkout->currency_code);
        $expected = GatewayMinorUnits::fromMoney($checkout->money('amount'), $currency);
        if (! is_object($attributes) || ! MorPaymentGuard::paidCovers($checkout, $attributes->total ?? null, $attributes->currency ?? null, $expected)) {
            Log::warning("[lemon_squeezy] paid total does not cover checkout transaction {$checkout->id}; not settled");
            MorPaymentGuard::refused('lemon_squeezy', $paymentId, 'paid total does not cover the checkout');

            return false;
        }

        return true;
    }

    /**
     * BL-038: the 'initial' invoice is acknowledged without crediting — order_created settles that money. When no
     * Lemon Squeezy payment of this account and plan is settled yet, that is logged and alerted (once per invoice:
     * the event is processed once), so a lost order_created is never silent.
     */
    private static function checkInitialSettled(object $customData, mixed $invoiceId): void
    {
        $settled = Transaction::query()->withoutGlobalScopes()->where('transaction_gatway', 'lemon_squeezy')
            ->where('status', Transaction::STATUS_SUCCESS)
            ->where('user_id', (int) ($customData->user_id ?? 0))->where('product_id', (int) ($customData->product_id ?? 0))
            ->exists();
        if (! $settled) {
            $invoice = is_scalar($invoiceId) ? (string) $invoiceId : '(no id)';
            Log::warning('[lemon_squeezy] initial subscription invoice '.$invoice.' acknowledged, but no settled order_created payment for this account and plan');
            AlertDispatcher::send('payments.lemon_initial_unsettled', 'lemon_squeezy initial invoice '.$invoice.' (user '.(int) ($customData->user_id ?? 0).') has no settled order_created payment yet: check the order at Lemon Squeezy');
        }
    }

    /** BL-038: a refused settlement of captured money raises an alert (not only a 400). */
    private static function settledOrAlerted(mixed $response, mixed $paymentId): mixed
    {
        // 503 = the same payment is being settled right now (WebhookReceipts): Lemon Squeezy retries, no alert.
        if ($response instanceof Response && $response->getStatusCode() >= 400 && $response->getStatusCode() !== 503) {
            MorPaymentGuard::refused('lemon_squeezy', $paymentId, 'the settlement answered '.$response->getStatusCode());
        }

        return $response;
    }

    /**
     * Lane D: the settled transaction an order_refunded event fully reverses, or null when the refund is partial, in
     * another currency, or names no settled Lemon Squeezy payment. LS amounts are integers in the currency's minor
     * unit; `status` is `refunded` for a full refund and `partial_refund` otherwise.
     */
    private static function fullyRefundedTransaction(mixed $order): ?Transaction
    {
        $orderId = is_object($order) ? ($order->id ?? null) : null;
        if (! is_scalar($orderId) || (string) $orderId === '') {
            return null;
        }
        $transaction = Transaction::where('payment_id', (string) $orderId)
            ->where('transaction_gatway', 'lemon_squeezy')
            ->where('status', Transaction::STATUS_SUCCESS)
            ->latest('id')
            ->first();
        if ($transaction === null) {
            return null;
        }

        $attributes = $order->attributes ?? null;
        $status = is_object($attributes) ? (string) ($attributes->status ?? '') : '';
        $amount = is_object($attributes) ? ($attributes->refunded_amount ?? null) : null;
        $currency = is_object($attributes) ? strtoupper((string) ($attributes->currency ?? '')) : '';
        $txCurrency = strtoupper((string) $transaction->currency_code);
        if ($status !== 'refunded' || ! is_numeric($amount) || $currency !== $txCurrency
            || (int) $amount < GatewayMinorUnits::fromMoney($transaction->money('amount'), $txCurrency)) {
            Log::warning("[lemon_squeezy] refund on order {$orderId} is partial or in another currency ({$status}, {$amount} {$currency}); transaction {$transaction->id} left as is, reconcile by hand");

            return null;
        }

        return $transaction;
    }

    /**
     * Handle the Webhook
     *
     * @return JsonResponse
     */
    public static function webhook(Request $request)
    {
        $secret = config('settings.lemon_squeezy_webhook_secret');
        $signature = $request->header('X-Signature');

        if (empty($secret) || empty($signature)
            || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), (string) $signature)) {
            return response()->json(['message' => 'invalid signature', 'status' => 400], 400);
        }

        $payload = json_decode($request->getContent());
        $event = $payload->meta->event_name ?? null;
        $customData = $payload->meta->custom_data ?? null;

        // J25-7: once per verified event (WebhookReceipts): a re-delivered or concurrent copy is a 200 no-op.
        return WebhookReceipts::once('lemon_squeezy', WebhookReceipts::key($event, $payload->data->id ?? null, $payload->data->attributes->updated_at ?? null, WebhookReceipts::fingerprint($request)), function () use ($event, $customData, $payload) {
            switch ($event) {
                case 'order_created':
                    /* Settles the money for one-time purchases AND the first payment
                       of a subscription. payment_id = LS order id (idempotent in
                       processWebhook). Add-on metadata routes to processAddonWebhook
                       inside processWebhook, mirroring Stripe. */
                    if (! $customData) {
                        MorPaymentGuard::refused('lemon_squeezy', $payload->data->id ?? null, 'no custom data');

                        return response()->json(['status' => 400], 400);
                    }
                    if (! self::paymentMatches($customData, $payload->data->attributes ?? null, $payload->data->id ?? null)) {
                        return response()->json(['status' => 400], 400);
                    }

                    /* BL-038: the settled checkout row is marked, so the hourly cleanup keeps it for every
                       subscription_payment_success bound to it (before: deleted after 3 hours, each renewal refused). */
                    $response = Fulfilment::webhook('lemon_squeezy', $customData, $payload->data->id ?? null, null);
                    MorPaymentGuard::markSettled($response, $customData, null);
                    // BL-038: subscription_created may have come first — stamp this payment with its subscription.
                    $subscription = data_get(MorPaymentGuard::checkoutTransaction($customData)?->details, 'lemon_subscription_id');
                    if ($response instanceof Response && $response->getStatusCode() === 200) {
                        MorPaymentGuard::stampSubscription('lemon_squeezy', $payload->data->id ?? null, $subscription);
                    }

                    return self::settledOrAlerted($response, $payload->data->id ?? null);

                case 'subscription_created':
                    /* Money already settled by order_created for the same purchase —
                       calling processWebhook here would double-credit (different
                       payment_id). Only record the subscription id on the Upgrade. */
                    /* WI-06d: custom data is buyer-supplied on a public buy link —
                       only stamp the account of one of our checkout transactions. */
                    $checkout = MorPaymentGuard::checkoutTransaction($customData);
                    $userId = $checkout?->user_id;
                    $subId = $payload->data->id ?? null;

                    if ($userId && $subId) {
                        Upgrade::where('user_id', $userId)
                            ->update(['unique_id' => 'lemon_squeezy###'.$subId]);
                        // BL-038: the first payment (its order) belongs to this subscription — renewals can be bound to it.
                        MorPaymentGuard::stampSubscription('lemon_squeezy', $payload->data->attributes->order_id ?? null, $subId);
                        // Also kept on the checkout row: when this arrives before order_created settled, that settlement
                        // stamps its payment from here.
                        $details = (array) json_decode((string) json_encode($checkout->details ?? []), true);
                        $details['lemon_subscription_id'] = (string) $subId;
                        $checkout->forceFill(['details' => $details])->save();
                    }

                    return response()->json(['message' => 'subscription captured', 'status' => 200], 200);

                case 'subscription_payment_success':
                    /* Renewal: data.id is the subscription-invoice id — a fresh
                       payment_id per billing period. */
                    if (! $customData) {
                        MorPaymentGuard::refused('lemon_squeezy', $payload->data->id ?? null, 'no custom data');

                        return response()->json(['status' => 400], 400);
                    }
                    $attributes = $payload->data->attributes ?? null;
                    /* BL-038: the first period is settled by order_created (its own payment id): the subscription's
                       'initial' invoice is the same money and would credit the period twice. Acknowledged, nothing
                       credited; renewals ('renewal') settle below. */
                    if (is_object($attributes) && ($attributes->billing_reason ?? null) === 'initial') {
                        self::checkInitialSettled($customData, $payload->data->id ?? null);

                        return response()->json(['message' => 'initial payment settled by order_created', 'status' => 200], 200);
                    }

                    $subId = is_object($attributes) ? ($attributes->subscription_id ?? null) : null;
                    if (! self::paymentMatches($customData, $attributes, $payload->data->id ?? null, $subId)) {
                        return response()->json(['status' => 400], 400);
                    }

                    $response = Fulfilment::webhook('lemon_squeezy', $customData, $payload->data->id ?? null, $subId);
                    MorPaymentGuard::markSettled($response, $customData, true);
                    MorPaymentGuard::stampSubscription('lemon_squeezy', $payload->data->id ?? null, $subId);

                    return self::settledOrAlerted($response, $payload->data->id ?? null);

                case 'order_refunded':
                    /* M3: reverse the linked Transaction + end entitlement. The
                       order id was the payment_id recorded at order_created.
                       Lane D (mirrors Paddle): only a refund of the WHOLE payment in
                       its currency does so; LS also fires order_refunded for a
                       partial refund, which before reversed the full amount and
                       expired the plan. A partial refund changes nothing here. */
                    $refunded = self::fullyRefundedTransaction($payload->data ?? null);
                    if ($refunded === null) {
                        return response()->json(['message' => 'partial refund ignored', 'status' => 200], 200);
                    }
                    Fulfilment::morRefund('lemon_squeezy', $refunded->payment_id);

                    return response()->json(['message' => 'refunded', 'status' => 200], 200);

                case 'subscription_cancelled':
                    /* The merchant cancelled but has paid to the end of the current
                       period and keeps access until then — LS fires
                       `subscription_expired` separately when it actually runs out.
                       Expiring here forfeited paid time that was never refunded. */
                    Fulfilment::morCancelAtPeriodEnd('lemon_squeezy', $payload->data->id ?? null);

                    return response()->json(['message' => 'cancelled', 'status' => 200], 200);

                case 'subscription_expired':
                    /* M3: the paid period has ended → expire the local sub. */
                    Fulfilment::morCancel('lemon_squeezy', $payload->data->id ?? null);

                    return response()->json(['message' => 'expired', 'status' => 200], 200);

                case 'subscription_payment_failed':
                    /* M3: failed renewal → past_due with grace. */
                    $failedSubId = $payload->data->attributes->subscription_id ?? $payload->data->id ?? null;
                    Fulfilment::morPastDue('lemon_squeezy', $failedSubId);

                    return response()->json(['message' => 'past_due', 'status' => 200], 200);

                default:
                    /* Unknown events acknowledged so LS stops retrying. */
                    return response()->json(['message' => 'ignored', 'status' => 200], 200);
            }
        }, fn () => response()->json(['message' => 'duplicate', 'status' => 200], 200));
    }
}
