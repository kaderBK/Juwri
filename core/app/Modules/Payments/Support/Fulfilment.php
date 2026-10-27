<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Support;

use Juwri\Core\Support\Hooks;
use Juwri\Modules\Payments\Contracts\OrderPaymentHandler;
use Juwri\Modules\Payments\Contracts\TransactionFulfiller;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;

/**
 * P1-08c: static entry point the gateway controllers / adapters call once a payment is confirmed. Delegates to the
 * bound {@see TransactionFulfiller} (Billing) — or, for a store order, {@see OrderPaymentHandler} (Ordering) — so
 * Payments never imports Billing or Ordering.
 */
final class Fulfilment
{
    public static function fulfil(Transaction $transaction): mixed
    {
        return app(TransactionFulfiller::class)->fulfil($transaction);
    }

    /**
     * A verified gateway webhook settles a subscription / MoR payment ($paymentId: the provider's payment, invoice or
     * order id — a fresh one per renewal). J25-7: once per (gateway, payment) through the webhook receipts, so a
     * re-delivered or concurrent copy is a 200 no-op instead of reaching the fulfiller's check-then-insert twice.
     */
    public static function webhook(string $gateway, mixed $metadata, mixed $paymentId, mixed $subscriptionId): mixed
    {
        $key = is_scalar($paymentId) && (string) $paymentId !== '' ? 'payment:'.$paymentId : null;

        return WebhookReceipts::once(
            $gateway,
            $key,
            fn () => app(TransactionFulfiller::class)->webhook($gateway, $metadata, $paymentId, $subscriptionId),
            fn () => response()->json(['message' => 'duplicate', 'status' => 200], 200),
        );
    }

    /* P1-13 13g: merchant-of-record (Paddle / Lemon Squeezy) lifecycle webhooks of a platform subscription — refund,
       gateway-side cancel / expiry, failed renewal — go to the TransactionFulfiller::MOR_* hook slots, which Billing (ee)
       fills (its MorWebhookLifecycleService); without it there is no platform subscription to change. Exceptions reach
       the gateway controller as before. */
    public static function morRefund(string $gateway, ?string $paymentId): void
    {
        Hooks::call(TransactionFulfiller::MOR_REFUND_SLOT, $gateway, $paymentId);
    }

    public static function morCancelAtPeriodEnd(string $gateway, ?string $subscriptionId): void
    {
        Hooks::call(TransactionFulfiller::MOR_CANCEL_AT_PERIOD_END_SLOT, $gateway, $subscriptionId);
    }

    public static function morCancel(string $gateway, ?string $subscriptionId): void
    {
        Hooks::call(TransactionFulfiller::MOR_CANCEL_SLOT, $gateway, $subscriptionId);
    }

    public static function morPastDue(string $gateway, ?string $subscriptionId): void
    {
        Hooks::call(TransactionFulfiller::MOR_PAST_DUE_SLOT, $gateway, $subscriptionId);
    }

    /** P1-08d: a store order's payment is confirmed. */
    public static function orderPaid(Transaction $transaction): mixed
    {
        return app(OrderPaymentHandler::class)->paid($transaction);
    }
}
