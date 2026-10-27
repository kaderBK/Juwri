<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Illuminate\Support\Collection;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\StoreTransfers;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;

/**
 * P1-23 part 6 — {@see StoreTransfers}: a store payment (gift card, store-credit top-up, reservation / catering deposit)
 * paid by wallet transfer or bank transfer stays `pending` until the STORE OWNER confirms the money reached the store's
 * own wallet / account (Order Hub, "Transfers to confirm"). Confirming claims the row atomically (pending → success)
 * and credits it through the outbox like any gateway confirmation (Settlement::settleClaimed), so a double click or a
 * concurrent confirm credits once. Store orders paid by wallet transfer keep their own Order Hub flow
 * (ManagesOrders::confirmWalletTransfer → paySuccess).
 */
final class ManualStoreTransfers implements StoreTransfers
{
    public function pendingForStore(int $storeId): Collection
    {
        return Transaction::query()
            ->where('store_id', $storeId)
            ->whereIn('transaction_method', StorePayments::METHODS)
            ->whereIn('transaction_gatway', self::GATEWAYS)
            ->where('status', Transaction::STATUS_PENDING)
            ->latest('id')
            ->get()
            ->filter(fn (Transaction $t) => empty(optional($t->details)->rejected))
            ->values();
    }

    public function confirm(Transaction $transaction, int $userId): bool
    {
        if (! $this->ownersPending($transaction, $userId)) {
            return false;
        }

        // The claim: only the confirm that moves THIS store's pending manual payment to paid credits it.
        return Settlement::settleClaimed($transaction, fn (): bool => Transaction::query()->whereKey($transaction->getKey())
            ->where('store_id', $transaction->store_id)
            ->whereIn('transaction_method', StorePayments::METHODS)
            ->whereIn('transaction_gatway', self::GATEWAYS)
            ->where('status', Transaction::STATUS_PENDING)
            ->update(['status' => Transaction::STATUS_SUCCESS]) === 1);
    }

    public function reject(Transaction $transaction, int $userId): bool
    {
        if (! $this->ownersPending($transaction, $userId)) {
            return false;
        }
        $details = (array) ($transaction->details ?? []);
        $details['rejected'] = true;
        $details['rejected_at'] = now()->toIso8601String();

        return Transaction::query()->whereKey($transaction->getKey())->where('status', Transaction::STATUS_PENDING)
            ->update(['status' => 'cancel', 'details' => json_encode($details)]) === 1;
    }

    public function paidToStore(Transaction $transaction): bool
    {
        return Settlement::usesStoreGateway($transaction);
    }

    /** A pending manual STORE payment of a store $userId owns (never an order, never a platform purchase). */
    private function ownersPending(Transaction $transaction, int $userId): bool
    {
        if (! Settlement::isStorePayment($transaction)
            || ! in_array($transaction->transaction_gatway, self::GATEWAYS, true)
            || $transaction->status !== Transaction::STATUS_PENDING) {
            return false;
        }
        $ownerId = Store::query()->whereKey($transaction->store_id)->value('user_id');

        return $userId > 0 && $ownerId !== null && (int) $ownerId === $userId;
    }
}
