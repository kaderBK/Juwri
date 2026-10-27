<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use Illuminate\Support\Collection;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P1-23 part 6 public surface: manual transfers (wallet transfer, bank / wire transfer) of a store's OWN customers'
 * payments — gift cards, store-credit top-ups, reservation and catering deposits. No provider confirms them: the money
 * goes to the STORE's wallet / bank account, so only the STORE OWNER may confirm (or reject) one — never the platform.
 * A platform purchase paid by bank transfer is confirmed by the superadmin only, never by a store owner.
 * Implemented by Payments\Services\ManualStoreTransfers.
 */
interface StoreTransfers
{
    /** Manual tenders (payment_folder). */
    public const GATEWAYS = ['wallet_transfer', 'wire_transfer'];

    /**
     * The store's pending manual store payments awaiting its owner (newest first; rejected ones excluded).
     *
     * @return Collection<int, Transaction>
     */
    public function pendingForStore(int $storeId): Collection;

    /** The owner ($userId) confirms the money arrived: settles once. False when not theirs / not pending. */
    public function confirm(Transaction $transaction, int $userId): bool;

    /** The owner ($userId) says no money arrived: the payment is cancelled, nothing is credited. */
    public function reject(Transaction $transaction, int $userId): bool;

    /** Paid to a store (an order or a store payment) — the platform must never confirm it. */
    public function paidToStore(Transaction $transaction): bool;
}
