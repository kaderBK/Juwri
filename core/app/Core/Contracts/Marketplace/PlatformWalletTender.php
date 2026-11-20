<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Marketplace;

use App\Models\Order;
use Juwri\Core\Editions\Community\NoPlatformWallet;

/**
 * grocery-nits2 extension point: the platform (Fusion) wallet tender of an order (`orders.mk_wallet_applied`). The
 * Marketplace module (ee) owns the shopper wallets; Community has none ({@see NoPlatformWallet}).
 */
interface PlatformWalletTender
{
    /**
     * Give `$amount` (order currency) of the order's platform-wallet tender back to the wallet that paid it. The
     * caller lowers `mk_wallet_applied` by the amount when this returns true. `$key` is unique per return (a replay
     * of the same key returns nothing twice). False when nothing could go back.
     */
    public function returnPart(Order $order, float $amount, string $key): bool;

    /**
     * money-tail 4: a cancelled, voided or fully refunded order gives the rest of its platform-wallet tender back to
     * the wallet that paid it and clears `mk_wallet_applied`. Idempotent per order (a repeat returns 0); never gives
     * back a part returnPart already returned. Returns what went back on this call (order currency). money-last 2:
     * `$keep` (order currency) is a part already refunded another way (a refund to store credit): it is not given back.
     */
    public function reverseOrder(Order $order, float $keep = 0.0): float;
}
