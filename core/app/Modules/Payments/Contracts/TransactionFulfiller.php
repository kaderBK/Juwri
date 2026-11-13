<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use Juwri\Modules\Payments\Models\Transaction;

/**
 * P1-08c fulfilment registry: what a settled platform Transaction buys (plan, add-on, app, wallet top-up, gift card,
 * reseller tier, …) is not Payments' business. Gateways settle the money and hand the Transaction over; the Billing
 * module binds the implementation (CheckoutController::updateUserPlan / processWebhook today).
 */
interface TransactionFulfiller
{
    /** P1-13 13g: hook slots of the merchant-of-record subscription webhooks (Paddle / Lemon Squeezy), filled by Billing. */
    public const MOR_REFUND_SLOT = 'payment.mor.refund';

    public const MOR_CANCEL_AT_PERIOD_END_SLOT = 'payment.mor.cancel-at-period-end';

    public const MOR_CANCEL_SLOT = 'payment.mor.cancel';

    public const MOR_PAST_DUE_SLOT = 'payment.mor.past-due';

    /** A confirmed one-time payment: grant what the transaction bought (idempotent). */
    public function fulfil(Transaction $transaction): mixed;

    /**
     * A gateway webhook for a subscription/one-time checkout identified by its metadata: record it and grant.
     * Returns the HTTP answer for the gateway.
     */
    public function webhook(string $gateway, mixed $metadata, mixed $paymentId, mixed $subscriptionId): mixed;
}
