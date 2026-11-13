<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Models\DeliveryOrder;
use App\Models\Order;
use App\Services\OrderApiService;

/**
 * T3 shared inbound pipeline for every aggregator. Resolves the tenant from the stored
 * connection (never the payload), enforces idempotency via the delivery_orders
 * (provider, external_id) unique index, and creates the Order through the ONE creation
 * path (OrderApiService::create) tagged with the channel + commission fee.
 */
class AggregatorIngestor
{
    /**
     * Ingest a verified payload. Returns the created (or existing) Order, or null when
     * the store is unknown or the channel is paused. Assumes the caller already verified
     * the webhook signature and resolved $connection.
     */
    public static function ingest(AggregatorProvider $provider, AggregatorConnection $connection, array $payload): ?Order
    {
        if ($connection->isPaused()) {
            return null; // channel paused → drop (aggregator will retry/queue)
        }

        $externalId = $provider->externalOrderId($payload);
        if ($externalId === null || $externalId === '') {
            return null;
        }

        // Idempotency fast-path: a replayed webhook returns the original order.
        $existing = DeliveryOrder::where('provider', $provider->key())
            ->where('external_id', $externalId)
            ->first();
        if ($existing && $existing->order_id) {
            return Order::find($existing->order_id);
        }

        // Atomic claim: insert the dedup row (order_id null) BEFORE creating the
        // Order. Two concurrent deliveries of the same external id would both pass
        // the SELECT above and create duplicate local orders; the unique
        // (provider, external_id) index makes the loser throw here instead.
        try {
            $claim = DeliveryOrder::create([
                'provider'      => $provider->key(),
                'external_id'   => $externalId,
                'order_id'      => null,
                'store_id' => $connection->store_id,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $existing = DeliveryOrder::where('provider', $provider->key())
                ->where('external_id', $externalId)->first();

            return $existing && $existing->order_id ? Order::find($existing->order_id) : null;
        }

        try {
            $ownerId = (int) \Juwri\Core\Models\Store::where('id', $connection->store_id)->value('user_id');
            $data = $provider->mapOrder($payload, $connection);
            $data['source'] = $provider->key();
            $data['delivery_by_partner'] = true; // the delivery app delivers it — not the store's own delivery (plan gate)
            $data['branch_id'] = $data['branch_id'] ?? $connection->branch_id;

            $order = OrderApiService::create($ownerId, (int) $connection->store_id, $data);

            // Commission/fee onto the one money path (analytics reads orders.channel_fee).
            if (!empty($data['channel_fee'])) {
                $order->channel_fee = round((float) $data['channel_fee'], 2);
                $order->save();
            }

            $claim->update(['order_id' => $order->id]);
        } catch (\Throwable $e) {
            // Roll the claim back so a legitimate retry can re-ingest this order.
            $claim->delete();
            throw $e;
        }

        // Optional post-ingest hook (e.g. Uber Eats must accept the order into the POS
        // within the deadline). Best-effort — never fail the ingest on an accept error.
        if (method_exists($provider, 'afterIngest')) {
            try {
                $provider->afterIngest($order, $connection, $payload);
            } catch (\Throwable $e) {
                // accept/deny is retried by ops; the order already exists locally
            }
        }

        return $order;
    }
}
