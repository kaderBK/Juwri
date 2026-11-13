<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers;

use App\Models\Order;
use Juwri\Modules\Delivery\Models\CourierDelivery;

/**
 * T4 courier dispatch contract. Own-fleet and every third-party courier (Uber Direct,
 * DoorDash Drive) implement this; DispatchService is the single entry point that quotes
 * and dispatches through it. Third-party providers record a CourierDelivery for tracking;
 * own-fleet keeps using orders.driver_id.
 */
interface CourierProvider
{
    /** Provider key: 'own' | 'uber_direct' | 'doordash'. */
    public function key(): string;

    /** Human label for the dispatch UI. */
    public function label(): string;

    /** Is this provider connected/usable for the restaurant? */
    public function availableFor(int $storeId): bool;

    /** Estimate fee + ETA for delivering this order. Null when it cannot quote. */
    public function quote(Order $order): ?CourierQuote;

    /** Dispatch the order to this courier. Returns a CourierDelivery (3rd-party) or null (own-fleet). */
    public function dispatch(Order $order, ?CourierQuote $quote = null): ?CourierDelivery;

    /** Cancel an in-flight delivery. Returns true on success. */
    public function cancel(Order $order): bool;
}
