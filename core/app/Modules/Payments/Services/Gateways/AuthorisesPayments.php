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
     * Capture `$amount` (≤ the authorised amount, in the transaction's currency) of the authorised hold.
     *
     * @return array{ok: bool, reference: string|null, message: string|null}
     */
    public function capture(Transaction $transaction, Money $amount): array;

    /**
     * Release the authorised hold without capturing anything.
     *
     * @return array{ok: bool, reference: string|null, message: string|null}
     */
    public function void(Transaction $transaction): array;
}
