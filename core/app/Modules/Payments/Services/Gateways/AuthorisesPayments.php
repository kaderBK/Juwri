<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P3-09 (basket §9, D7): a gateway that can put a card hold and capture later. A pending transaction whose
 * `details.capture` is `manual` is started in authorise mode by `initiate()`; the provider's confirmation is recorded
 * with {@see Settlement::authorise()} (transaction status `authorised`), never as a payment. The final amount is then
 * captured once — never above the authorised amount (CardHolds checks it before any provider call) — or the hold is
 * voided.
 *
 * Implemented by the Stripe (PaymentIntent `capture_method=manual`) and PayPal (order `intent=AUTHORIZE`) adapters;
 * the E2E Stub gateway authorises in-process (CardHolds). Gateways without it are hidden for weighed baskets unless
 * the store refunds the weighed difference.
 */
interface AuthorisesPayments
{
    /**
     * Capture `$amount` (≤ the authorised amount, in the transaction's currency) of the authorised hold. The
     * `$idempotencyKey` is the same for a retry after an unknown outcome (a timeout), so the provider answers the retry
     * with the first capture instead of capturing again.
     *
     * @return array{ok: bool, reference: string|null, message: string|null}
     */
    public function capture(Transaction $transaction, Money $amount, string $idempotencyKey): array;

    /**
     * Whether a capture retried with the same idempotency key is safe (the provider never captures twice). When it
     * is not, a capture with an unknown outcome is never retried automatically: it waits for a manual check.
     */
    public function capturesIdempotently(): bool;

    /**
     * Release the authorised hold without capturing anything.
     *
     * @return array{ok: bool, reference: string|null, message: string|null}
     */
    public function void(Transaction $transaction): array;
}
