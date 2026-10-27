<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\User;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * BL-038 P1: a recurring charge (a subscription renewal) whose paid amount or currency is not one the subscription was
 * sold at. It extends nothing: a REVIEW row is recorded instead (status `review`, the evidence in details.amount_review),
 * with a warning log and a platform alert (`payments.amount_mismatch`).
 *
 * The review row's payment_id is `review:<the gateway's payment id>` — never the payment id itself, which stays free
 * for the settlement. Release by hand (ops), once the charge is checked at the gateway:
 *   php artisan payments:settle-held-renewal <review transaction id>
 * settles the payment through the normal settlement (as if the webhook had been accepted) and stamps the review row
 * `details.amount_review.released_at`. A replay of the held event finds its review row and alerts nothing again.
 */
final class RenewalAmountHold
{
    /**
     * @param  object  $metadata  the checkout attributes round-tripped through the gateway
     * @param  array<string, mixed>  $evidence  paid amount (minor units) and currency, the expected amounts
     */
    public static function record(string $gateway, object $metadata, string $paymentId, ?string $subscriptionId, array $evidence): ?Transaction
    {
        $evidence = ['kind' => 'renewal', 'gateway' => $gateway, 'payment_id' => $paymentId, 'subscription_id' => $subscriptionId] + $evidence;
        Log::warning('[payment-amount] '.$gateway.' recurring charge held for review', $evidence);

        $reviewId = self::reviewId($paymentId);
        if ($paymentId === '' || Transaction::query()->withoutGlobalScopes()->whereIn('payment_id', [$paymentId, $reviewId])->where('transaction_gatway', $gateway)->exists()) {
            return null; // already held (or settled): never alert a replay twice
        }
        // What a manual release needs to settle it later (payments:settle-held-renewal).
        $evidence['metadata'] = (array) $metadata;
        $userId = (int) ($metadata->user_id ?? 0);
        $userId = $userId > 0 && User::query()->whereKey($userId)->exists() ? $userId : 0;
        $currency = strtoupper(trim((string) ($metadata->currency_code ?? ''))) ?: null;
        try {
            $row = Transaction::create([
                'product_name' => (string) ($metadata->product_name ?? 'Subscription renewal'),
                'product_id' => (int) ($metadata->product_id ?? 0),
                'user_id' => $userId > 0 ? $userId : null,
                'amount' => is_numeric($metadata->amount ?? null) ? $metadata->amount : 0,
                'currency_code' => $currency,
                'transaction_method' => (string) ($metadata->transaction_method ?? 'membership'),
                'frequency' => in_array($metadata->frequency ?? null, ['MONTHLY', 'YEARLY', 'LIFETIME'], true) ? $metadata->frequency : null,
                'transaction_gatway' => $gateway,
                'payment_id' => $reviewId,
                'transaction_description' => 'Recurring charge held for review (amount or currency mismatch)',
                'details' => ['amount_review' => $evidence],
                'status' => Transaction::STATUS_REVIEW,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // a concurrent delivery recorded it first
        }

        // Low-cardinality message (AlertDispatcher de-dupes per day); the detail is in the log line above.
        AlertDispatcher::send('payments.amount_mismatch', $gateway.': recurring charge held for review (transaction '.$row->id.'); once checked at the gateway, release it with: php artisan payments:settle-held-renewal '.$row->id);

        return $row;
    }

    /** The review row's own payment id (the gateway's payment id stays free for its settlement). */
    public static function reviewId(string $paymentId): string
    {
        return 'review:'.$paymentId;
    }
}
