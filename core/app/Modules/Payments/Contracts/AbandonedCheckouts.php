<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use Juwri\Modules\Payments\Models\Transaction;

/**
 * BL-038 review — Payments' public part for the life of a checkout row: send it to its gateway, mark it settled when
 * its payment settles (a merchant-of-record checkout stays findable for its renewals), and mark it dead (`abandoned`)
 * only once it is provably closed at its gateway, or its gateway's maximum lifetime has passed. Listeners of
 * {@see HOOK} hand back what an abandoned checkout held. Bound to Payments' AbandonedCheckout.
 */
interface AbandonedCheckouts
{
    /** Hook slot: fn (Transaction $abandoned) — called once, after the row is marked. */
    public const HOOK = 'payments.checkout.abandoned';

    /** True when the pending checkout is now abandoned; false when it is not open or may still be paid. */
    public function abandon(Transaction $transaction): bool;

    /**
     * Send a checkout that holds a promotion (`coupon`) to its gateway: the gateway is recorded on it first (so it can
     * be closed later), and when the gateway cannot start — it throws, or flashes an error and sends the buyer back —
     * the checkout is abandoned at once if it is provably closed. A checkout without a promotion just goes through.
     *
     * @param  \Closure(Transaction): mixed  $pay
     */
    public function start(Transaction $transaction, string $gateway, \Closure $pay): mixed;

    /**
     * The payment of this checkout settled (called inside the settlement): a merchant-of-record checkout row is marked
     * `recurring_setup` when a gateway subscription follows (`$recurring` null = judged from the row), else
     * `checkout_settled`, so the cleanup keeps it for the gateway's later events. A row already marked keeps its status.
     */
    public function settled(int $checkoutId, ?bool $recurring = null): void;

    /** Whether this checkout row stands for a settled payment (paid, or a merchant-of-record checkout marked settled). */
    public function isPaid(Transaction $checkout): bool;

    /** Whether this checkout row can no longer be paid and was not (abandoned, failed, cancelled, refunded, held…). */
    public function isTerminalUnpaid(Transaction $checkout): bool;

    /** Whether the checkout's gateway can still take its payment: false once that gateway's maximum lifetime passed. */
    public function withinLifetime(Transaction $checkout): bool;

    /** Whether the checkout's gateway has an API that closes an open checkout (an attempt that can be refused). */
    public function hasCloseApi(Transaction $checkout): bool;
}
