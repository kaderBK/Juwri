<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Zomato;

use App\Models\Order;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Models\DeliveryOrder;
use Juwri\Modules\Channels\Services\Aggregators\AbstractAggregatorProvider;
use Juwri\Modules\Channels\Services\Aggregators\India\IndiaItemResolver;

/**
 * PARITY-RUN T7 — Zomato behind the shared AggregatorProvider seam.
 *
 * Inbound: `order.placed` → the shared AggregatorIngestor resolves the tenant from
 * the STORED connection (never the payload), verifies the HMAC, dedupes on
 * `delivery_orders(provider, external_id)` and creates the Order through the ONE
 * creation path (`OrderApiService::create`). This driver never builds an Order.
 *
 * Outbound: auto-acknowledge on ingest (unless the connection is set to manual
 * accept), then lifecycle status pushes driven by the `Order::booted()` update
 * choke point via {@see \Juwri\Modules\Channels\Services\Aggregators\AggregatorStatusPusher}.
 *
 * Everything on the wire is ASSUMED until partner certification (BD-ACTIONS #11)
 * and is confined to {@see ZomatoPayloadMapper} + {@see ZomatoClientInterface}.
 */
class ZomatoProvider extends AbstractAggregatorProvider
{
    /** The superadmin kill-switch / plan key this channel is sold under. */
    public const FEATURE_KEY = 'aggregator_zomato';

    protected string $signatureHeader = ZomatoPayloadMapper::SIGNATURE_HEADER;

    protected string $secretKey = ZomatoPayloadMapper::SECRET_KEY;

    /**
     * Our order lifecycle → Zomato's POS status vocabulary. A status with no
     * mapping is simply not pushed (rather than pushed as something wrong).
     */
    public const STATUS_MATRIX = [
        'pending' => 'ACKNOWLEDGED',
        'new' => 'ACKNOWLEDGED',
        'accepted' => 'ACKNOWLEDGED',
        'preparing' => 'FOOD_PREPARATION',
        'ready' => 'READY_FOR_PICKUP',
        'served' => 'READY_FOR_PICKUP',
        'out_for_delivery' => 'DISPATCHED',
        'completed' => 'DELIVERED',
        'delivered' => 'DELIVERED',
        'cancelled' => 'CANCELLED',
    ];

    public function key(): string
    {
        return 'zomato';
    }

    public function externalStoreId(array $payload): ?string
    {
        return ZomatoPayloadMapper::storeId($payload);
    }

    public function externalOrderId(array $payload): ?string
    {
        return ZomatoPayloadMapper::orderId($payload);
    }

    public function mapOrder(array $payload, AggregatorConnection $connection): array
    {
        $storeId = (int) $connection->store_id;

        $items = [];
        $unmapped = [];

        foreach (ZomatoPayloadMapper::lines($payload) as $line) {
            $line = (array) $line;
            $qty = ZomatoPayloadMapper::lineQty($line);
            $menuId = IndiaItemResolver::menuId($storeId, ZomatoPayloadMapper::lineCode($line));

            if ($menuId) {
                $items[] = ['item_id' => $menuId, 'quantity' => $qty];

                continue;
            }

            // Surfaced, never silently dropped — the contract in AggregatorProvider.
            $unmapped[] = $qty.'× '.ZomatoPayloadMapper::lineName($line);
        }

        $note = 'Via Zomato';
        if ($instructions = ZomatoPayloadMapper::instructions($payload)) {
            $note .= ' · '.$instructions;
        }
        if ($unmapped) {
            $note .= ' · '.__('Unmapped items (review)').': '.implode(', ', $unmapped);
        }

        $customer = ZomatoPayloadMapper::customer($payload);

        return [
            'type' => 'delivery',
            'customer_name' => $customer['name'] ?? null,
            'phone' => (string) ($customer['phone'] ?? $customer['mobile'] ?? ''),
            'address' => ZomatoPayloadMapper::address($payload),
            'note' => $note,
            'channel_fee' => ZomatoPayloadMapper::channelFee($payload),
            'items' => $items,
        ];
    }

    /**
     * Acknowledge the order inside Zomato's accept window. Best-effort by
     * contract — the Ingestor try/catches this, and a failed ack is retried by
     * ops while the order already exists locally.
     */
    public function afterIngest(Order $order, AggregatorConnection $connection, array $payload): void
    {
        if ((int) ($connection->auto_accept ?? 1) === 0) {
            return;
        }

        $externalId = $this->externalOrderId($payload);
        if (! $externalId) {
            return;
        }

        ZomatoClientFactory::make((int) $connection->store_id)
            ->acknowledgeOrder($externalId, $this->prepMinutes($connection));
    }

    public function statusCodeFor(Order $order): ?string
    {
        return self::STATUS_MATRIX[(string) ($order->status ?? '')] ?? null;
    }

    public function pushStatus(Order $order, AggregatorConnection $connection): void
    {
        $status = $this->statusCodeFor($order);
        if ($status === null) {
            return;
        }

        $externalId = DeliveryOrder::where('provider', $this->key())
            ->where('order_id', $order->id)
            ->value('external_id');

        if (! $externalId) {
            return;
        }

        ZomatoClientFactory::make((int) $connection->store_id)
            ->updateOrderStatus((string) $externalId, $status);
    }

    /** Owner-configured prep estimate sent with the acknowledgement. */
    public function prepMinutes(AggregatorConnection $connection): int
    {
        $minutes = (int) (($connection->settings['prep_minutes'] ?? 0) ?: 20);

        return max(1, min(120, $minutes));
    }

    /** The item code this driver publishes for a local menu id. */
    public static function itemCode(int $menuId): string
    {
        return ZomatoPayloadMapper::ITEM_CODE_PREFIX.$menuId;
    }
}
