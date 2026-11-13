<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Square;

use App\Models\Order;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeProvider;
use Juwri\Modules\Channels\Services\PosBridge\PosPushResult;
use Throwable;

/**
 * GAPS-RUN-2 B1-P2: Square POS driver. Pushes an order via POST /v2/orders with a
 * stable idempotency_key (so a retry never duplicates) and a PICKUP fulfillment
 * (an order only surfaces on Square POS with a fulfillment). Mapped menu items push
 * as catalog_object_id line items; unmapped items fall back to ad-hoc name/price.
 */
class SquareProvider implements PosBridgeProvider
{
    public function key(): string
    {
        return 'square';
    }

    public function label(): string
    {
        return 'Square';
    }

    public function testConnection(PosBridgeConnection $connection): bool
    {
        try {
            return SquareClientFactory::make((int) $connection->store_id)
                ->ping((string) $connection->external_location_id);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function pushOrder(Order $order, PosBridgeConnection $connection): PosPushResult
    {
        try {
            $client = SquareClientFactory::make((int) $order->store_id);
            $body = [
                // Stable per order → Square dedups a retry to the same order.
                'idempotency_key' => 'pos-sq-'.$order->id,
                'order' => [
                    'location_id' => (string) $connection->external_location_id,
                    'reference_id' => (string) $order->id,
                    'line_items' => $this->lineItems($order),
                    'fulfillments' => [[
                        'type' => 'PICKUP',
                        'state' => 'PROPOSED',
                        'pickup_details' => ['recipient' => ['display_name' => $order->customer_name ?: 'Online order']],
                    ]],
                ],
            ];
            $res = $client->createOrder($body);
            if (empty($res['id'])) {
                return PosPushResult::failure('no_order_id', $res);
            }

            return PosPushResult::success((string) $res['id'], $res);
        } catch (Throwable $e) {
            return PosPushResult::failure($e->getMessage());
        }
    }

    public function listItems(PosBridgeConnection $connection): array
    {
        try {
            return SquareClientFactory::make((int) $connection->store_id)->listCatalog();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array<int, array> Square line items, mapped or ad-hoc. */
    private function lineItems(Order $order): array
    {
        $currency = strtoupper((string) (store_options((int) $order->store_id, 'currency_code') ?: 'USD'));
        $lines = [];
        foreach ($order->items as $item) {
            $qty = (string) max(1, (int) ($item->quantity ?: 1));
            $external = PosItemMap::externalIdFor('square', (int) $item->item_id);
            if ($external) {
                $lines[] = ['catalog_object_id' => $external, 'quantity' => $qty];

                continue;
            }
            $menu = $item->menu;
            $price = (float) ($menu->price ?? 0);
            $lines[] = [
                'name' => $menu->name ?? 'Item',
                'quantity' => $qty,
                'base_price_money' => [
                    'amount' => (int) round($price * 100),
                    'currency' => $currency,
                ],
            ];
        }
        if (empty($lines)) {
            // Square rejects an order with no line items — send a zero placeholder.
            $lines[] = ['name' => 'Order #'.$order->id, 'quantity' => '1', 'base_price_money' => ['amount' => 0, 'currency' => $currency]];
        }

        return $lines;
    }
}
