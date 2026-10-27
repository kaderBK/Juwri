<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Models\DeliveryOrder;
use App\Models\Order;
use Juwri\Modules\Channels\Services\Aggregators\India\IndiaAggregatorSync;

/**
 * PARITY-RUN T7 — the missing half of the outbound status seam.
 *
 * `AggregatorProvider::pushStatus()` has existed since the T3 aggregator hub and
 * every driver implements it, but **nothing in the application ever called it**:
 * before this class the only caller in the entire repository was one assertion
 * inside `IntegrationsVerificationTest`. So a kitchen marking an aggregator order
 * ready told the aggregator nothing, and their rider was dispatched on a timer
 * rather than on the food actually being ready.
 *
 * This wires it to the documented single choke point for every status-write path —
 * the `Order::booted()` `updated()` hook (`Order.php:53`) — in the same guarded,
 * best-effort shape as the shopper-push and WhatsApp listeners already there. A
 * partner outage can never break an order transition.
 *
 * Providers whose `pushStatus()` is the abstract no-op (custom, Uber Eats) cost
 * one indexed lookup and do nothing, exactly as before.
 */
class AggregatorStatusPusher
{
    /**
     * Fan a local status change out to whichever aggregator owns this order.
     * Safe to call for every order: an order with no `delivery_orders` row —
     * i.e. almost all of them — returns after one indexed lookup.
     */
    public static function onOrderStatusChanged(Order $order): void
    {
        $link = DeliveryOrder::where('order_id', $order->id)->first();
        if (! $link || ! $link->provider) {
            return;
        }

        $provider = AggregatorRegistry::get((string) $link->provider);
        if (! $provider) {
            return;
        }

        // A channel the store no longer pays for must go quiet, not keep talking
        // to a partner on the owner's behalf. Store-scoped subject: the requester
        // here may be a kitchen tablet, a webhook or a cron.
        if (IndiaAggregatorSync::isIndiaProvider((string) $link->provider)
            && ! IndiaAggregatorSync::enabled((int) $order->store_id, (string) $link->provider)) {
            return;
        }

        $connection = AggregatorConnection::where('provider', $link->provider)
            ->where('store_id', $order->store_id)
            ->first();

        if (! $connection || $connection->isPaused()) {
            return;
        }

        $provider->pushStatus($order, $connection);
    }
}
