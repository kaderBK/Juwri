<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Juwri\Core\Services\AlertDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * WI-M6 — the one funnel for payment-gateway failures.
 *
 * Before this, 27 call sites under app/Http/Controllers/User/PaymentMethods did
 * `Log::info($e->getMessage())`. Two things made that a black hole:
 *
 *  1. config/logging.php sets the minimum level to `warning` when
 *     APP_ENV=production, so an `info` record is DISCARDED on the production box;
 *  2. the exception was caught and never rethrown, so `report()` was never called
 *     and the Sentry forwarder in app/Exceptions/Handler.php — which only runs for
 *     reported/unhandled exceptions — could never fire.
 *
 * Levels here are deliberate: `error` (above the production floor) plus `report()`
 * for the stack trace and Sentry. Only the two classes that mean "the platform's
 * own plumbing is wrong" also raise a PlatformAlert row, because AlertDispatcher
 * writes a DB row per distinct message and a declined card must never do that.
 */
class PaymentLog
{
    /**
     * A caught gateway error while STARTING a customer payment (`pay()`).
     * Logged + reported. No alert: a single failed checkout is not a platform incident.
     *
     * @param  array<string,mixed>  $context
     */
    public static function failure(string $gateway, \Throwable $e, array $context = []): void
    {
        Log::error('[payment] '.$gateway.': '.$e->getMessage(), $context + [
            'gateway' => $gateway,
            'exception' => get_class($e),
        ]);

        report($e);
    }

    /**
     * A caught error inside a SETTLEMENT handler (`ipn()` / `webhook()`), i.e. the
     * buyer may already have been charged. Logged, reported AND alerted.
     *
     * @param  array<string,mixed>  $context
     */
    public static function settlementFailed(string $gateway, \Throwable $e, array $context = []): void
    {
        Log::error('[payment-settlement] '.$gateway.': '.$e->getMessage(), $context + [
            'gateway' => $gateway,
            'exception' => get_class($e),
        ]);

        report($e);

        // AlertDispatcher de-dupes on (day, kind, message) — keep the message
        // LOW-CARDINALITY so a repeating fault is one open row, not thousands.
        // The volatile detail stays in the log line above.
        AlertDispatcher::send('payment', $gateway.': settlement handler failed');
    }

    /**
     * A webhook/IPN whose payload or signature was REJECTED. This is the platform's
     * own configuration or an attacker, never a customer — always alert.
     *
     * @param  array<string,mixed>  $context
     */
    public static function webhookRejected(string $gateway, string $reason, ?\Throwable $e = null, array $context = []): void
    {
        Log::error('[payment-webhook] '.$gateway.' rejected: '.$reason, $context + [
            'gateway' => $gateway,
            'reason' => $reason,
            'exception' => $e ? get_class($e) : null,
        ]);

        if ($e) {
            report($e);
        }

        AlertDispatcher::send('payment', $gateway.': webhook rejected ('.$reason.')');

        // P6-93: hourly rejection counters (platform-wide + per store) for payments:watch's rate rule.
        PaymentsWatch::countWebhookRejection(isset($context['store_id']) && is_numeric($context['store_id']) ? (int) $context['store_id'] : null);
    }
}
