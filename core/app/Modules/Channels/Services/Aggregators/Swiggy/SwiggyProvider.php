<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Swiggy;

use App\Models\Order;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Models\DeliveryOrder;
use Juwri\Modules\Channels\Services\Aggregators\AbstractAggregatorProvider;
use Juwri\Modules\Channels\Services\Aggregators\India\IndiaItemResolver;

/**
 * PARITY-RUN T7 — Swiggy behind the shared AggregatorProvider seam.
 *
 * Inbound: `NEW_ORDER` → the shared AggregatorIngestor resolves the tenant from the
 * STORED connection (never the payload), verifies the HMAC (Swiggy prefixes the
 * signature with `sha256=`, which the abstract normaliser strips), dedupes on
 * `delivery_orders(provider, external_id)` and creates the Order through the ONE
 * creation path. This driver never builds an Order.
 *
 * Outbound: auto-confirm on ingest (unless the connection is set to manual accept),
 * then lifecycle status pushes driven by the `Order::booted()` update choke point
 * via {@see \Juwri\Modules\Channels\Services\Aggregators\AggregatorStatusPusher}.
 *
 * Everything on the wire is ASSUMED until partner onboarding (BD-ACTIONS #12) and
 * is confined to {@see SwiggyPayloadMapper} + {@see SwiggyClientInterface}.
 */
class SwiggyProvider extends AbstractAggregatorProvider
{
    /** The superadmin kill-switch / plan key this channel is sold under. */
    public const FEATURE_KEY = 'aggregator_swiggy';

    protected string $signatureHeader = SwiggyPayloadMapper::SIGNATURE_HEADER;

    protected string $secretKey = SwiggyPayloadMapper::SECRET_KEY;

    /** Our order lifecycle → Swiggy's POS status vocabulary. */
    public const STATUS_MATRIX = [
        'pending' => 'ORDER_CONFIRMED',
        'new' => 'ORDER_CONFIRMED',
        'accepted' => 'ORDER_CONFIRMED',
        'preparing' => 'FOOD_PREPARED',
        'ready' => 'READY_FOR_PICKUP',
        'served' => 'READY_FOR_PICKUP',
        'out_for_delivery' => 'ORDER_PICKED_UP',
        'completed' => 'ORDER_DELIVERED',
        'delivered' => 'ORDER_DELIVERED',
        'cancelled' => 'ORDER_CANCELLED',
    ];

    public function key(): string
    {
        return 'swiggy';
    }

    public function externalStoreId(array $payload): ?string
    {
        return SwiggyPayloadMapper::storeId($payload);
    }

    public function externalOrderId(array $payload): ?string
    {
        return SwiggyPayloadMapper::orderId($payload);
    }

    public function mapOrder(array $payload, AggregatorConnection $connection): array
    {
        $storeId = (int) $connection->store_id;

        $items = [];
        $unmapped = [];

        foreach (SwiggyPayloadMapper::lines($payload) as $line) {
            $line = (array) $line;
            $qty = SwiggyPayloadMapper::lineQty($line);
            $menuId = IndiaItemResolver::menuId($storeId, SwiggyPayloadMapper::lineCode($line));

            if ($menuId) {
                $items[] = ['item_id' => $menuId, 'quantity' => $qty];

                continue;
            }

            $unmapped[] = $qty.'× '.SwiggyPayloadMapper::lineName($line);
        }

        $note = 'Via Swiggy';
        if ($instructions = SwiggyPayloadMapper::instructions($payload)) {
            $note .= ' · '.$instructions;
        }
        if ($unmapped) {
            $note .= ' · '.__('Unmapped items (review)').': '.implode(', ', $unmapped);
        }

        $customer = SwiggyPayloadMapper::customer($payload);

        return [
            'type' => 'delivery',
            'customer_name' => $customer['name'] ?? null,
            'phone' => (string) ($customer['phone'] ?? $customer['mobile'] ?? ''),
            'address' => SwiggyPayloadMapper::address($payload),
            'note' => $note,
            'channel_fee' => SwiggyPayloadMapper::channelFee($payload),
            'items' => $items,
        ];
    }

    /** Confirm inside Swiggy's accept window. Best-effort by contract. */
    public function afterIngest(Order $order, AggregatorConnection $connection, array $payload): void
    {
        if ((int) ($connection->auto_accept ?? 1) === 0) {
            return;
        }

        $externalId = $this->externalOrderId($payload);
        if (! $externalId) {
            return;
        }

        SwiggyClientFactory::make((int) $connection->store_id)
            ->confirmOrder($externalId, $this->prepMinutes($connection));
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

        SwiggyClientFactory::make((int) $connection->store_id)
            ->updateOrderStatus((string) $externalId, $status);
    }

    public function prepMinutes(AggregatorConnection $connection): int
    {
        $minutes = (int) (($connection->settings['prep_minutes'] ?? 0) ?: 20);

        return max(1, min(120, $minutes));
    }

    /** The item code this driver publishes for a local menu id. */
    public static function itemCode(int $menuId): string
    {
        return SwiggyPayloadMapper::ITEM_CODE_PREFIX.$menuId;
    }
}
