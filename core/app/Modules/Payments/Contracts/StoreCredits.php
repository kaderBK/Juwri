<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use Juwri\Modules\Payments\Models\Transaction;

/**
 * P1-13 13g: what a confirmed payment of a business's own customers buys (store credit top-up, gift card, reservation /
 * catering deposit) — Payments credits it in every edition; Billing's checkout calls it at the place those branches were.
 */
interface StoreCredits
{
    /** Outbox message type of a purchased gift card's delivery (kept from Billing). */
    public const GIFT_CARD_DELIVERY = 'billing.gift_card.deliver';

    /** Credit a confirmed store payment (after the caller's credited_at claim). False when it is not a store payment. */
    public function credit(Transaction $transaction): bool;

    /**
     * P3-10: a module above Payments settles its own store payment method (Bookings: `booking_deposit`). The method
     * must be listed in StorePayments::METHODS (so gateways charge it on the store's credentials).
     *
     * @param  callable(Transaction): void  $handler
     */
    public function settleWith(string $method, callable $handler): void;
}
