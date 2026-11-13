<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Contracts;

use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P3-06b (kit/api README §2.6, API-2, API-10): the public surface of the core-hosted payment of a headless storefront
 * order — the pending Transaction on the core pay page, its 15-minute window and the lazy expiry. Implemented by
 * Ordering\Services\Payments\HostedOrderPayments.
 */
interface HostedPayments
{
    /** Can one of the store's own payment gateways take this total (the core pay page offers it)? */
    public function storeCanTake(Store $store, float $total): bool;

    /**
     * P3-09 (D7, basket §9): can one of the store's gateways that authorise a card hold and capture later (the
     * weighed-basket gateways) take this amount?
     */
    public function storeCanHold(Store $store, float $amount): bool;

    /**
     * Opens (or returns the already open) pending payment of an order placed with `payment.mode = hosted`. P3-09: a
     * weighed basket passes `$holdMinor` (quote total + what the weighed lines may add): that amount is authorised (or,
     * on a gateway without authorise/capture when the store refunds the weighed difference, charged) and the final
     * amount captured after picking.
     */
    public function open(Store $store, Order $order, ?string $returnUrl, ?string $ip, ?int $holdMinor = null): Transaction;

    /** The order's hosted payment Transaction, if any. */
    public function transactionFor(Order $order): ?Transaction;

    /** Is the order still waiting for its hosted payment inside the window? */
    public function isOpen(Order $order): bool;

    /** Cancels an unpaid hosted order whose window ended (stock and tenders given back); false when nothing changed. */
    public function expire(Order $order): bool;
}
