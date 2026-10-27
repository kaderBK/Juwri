<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use Juwri\Modules\Payments\Models\Transaction;

/**
 * P1-08d: a confirmed gateway payment for a store ORDER (transaction_method 'order'). Payments settles the money and
 * hands the Transaction over; the Ordering module binds the implementation (PaymentController::paySuccess today).
 * Platform purchases go to {@see TransactionFulfiller} instead.
 */
interface OrderPaymentHandler
{
    /** Mark the order paid and run its side effects (idempotent: `consumed` is terminal). Returns the response. */
    public function paid(Transaction $transaction): mixed;

    /**
     * J25-7 part 3: the page the returning diner gets for a settled order payment (the WhatsApp hand-off + order
     * tracking script paid() printed) — the credit itself now runs through the outbox, whose output is not the
     * browser's.
     */
    public function buyerPage(Transaction $transaction): string;

    /**
     * P3-09: the gateway authorised a card hold for a store order (weighed basket): the order goes ahead for picking;
     * the final amount is captured later. Idempotent per transaction.
     */
    public function authorised(Transaction $transaction): void;
}
