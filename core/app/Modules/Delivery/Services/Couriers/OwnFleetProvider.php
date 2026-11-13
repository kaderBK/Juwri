<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers;

use App\Models\Order;
use App\Services\DeliveryService;
use Juwri\Core\Models\Branch;
use Juwri\Modules\Delivery\Models\CourierDelivery;

/**
 * T4: the default courier — the restaurant's own drivers. Wraps the existing own-fleet
 * flow (orders.driver_id + DeliveryService status) so ALL dispatch goes through
 * DispatchService without changing current behavior. "Quote" is the restaurant's flat
 * delivery charge; "dispatch" marks the order ready for a driver (assignment stays the
 * owner's existing manual/auto step). Always available.
 */
class OwnFleetProvider implements CourierProvider
{
    public function key(): string
    {
        return 'own';
    }

    public function label(): string
    {
        return __('Own drivers');
    }

    public function availableFor(int $storeId): bool
    {
        return true;
    }

    public function quote(Order $order): ?CourierQuote
    {
        $fee = Branch::effectiveDeliveryFee((int) $order->store_id, $order->pricingBranch()); // P5-31: the branch's own fee
        $currency = (string) (store_options((int) $order->store_id, 'currency_code') ?: 'USD');

        return new CourierQuote('own', $fee, null, null, true, $currency);
    }

    public function dispatch(Order $order, ?CourierQuote $quote = null): ?CourierDelivery
    {
        // Own-fleet: put the order in the deliverable pool (pending) if it isn't already
        // further along. A driver is assigned by the owner (manual today, auto in P4).
        if (empty($order->delivery_status) || $order->delivery_status === 'pending') {
            DeliveryService::setStatus($order, 'pending');
        }

        return null; // no CourierDelivery row for own fleet
    }

    public function cancel(Order $order): bool
    {
        DeliveryService::setStatus($order, 'failed');

        return true;
    }
}
