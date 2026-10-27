<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Clover;

use App\Models\Order;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeProvider;
use Juwri\Modules\Channels\Services\PosBridge\PosPushResult;
use Throwable;

/**
 * GAPS-RUN-2 B1-P3: Clover POS driver. Pushes an order through the atomic-order path
 * with a merchant-specific orderType id + line items mapped to Clover inventory ids
 * (unmapped → ad-hoc name/price). Stable idempotency key per order → replay-safe.
 *
 * Owner-UI copy must note: REST/atomic orders don't show in Clover Dining, and
 * kitchen printing only fires for valid inventory items (ad-hoc lines don't print).
 */
class CloverProvider implements PosBridgeProvider
{
    public function key(): string
    {
        return 'clover';
    }

    public function label(): string
    {
        return 'Clover';
    }

    public function testConnection(PosBridgeConnection $connection): bool
    {
        try {
            return CloverClientFactory::make((int) $connection->store_id)
                ->ping((string) $connection->external_location_id);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function pushOrder(Order $order, PosBridgeConnection $connection): PosPushResult
    {
        try {
            $client = CloverClientFactory::make((int) $order->store_id);
            $orderTypeId = $this->resolveOrderType($client);
            if ($orderTypeId === null) {
                return PosPushResult::failure('no_order_type');
            }
            $payload = [
                'orderCart' => [
                    'orderType' => ['id' => $orderTypeId],
                    'note'      => 'Order #' . $order->id,
                    'lineItems' => $this->lineItems($order),
                ],
            ];
            $res = $client->createAtomicOrder($payload, 'pos-clv-' . $order->id);
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
            return CloverClientFactory::make((int) $connection->store_id)->listInventory();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Pick an "online"-flavoured orderType id, else the first one. */
    private function resolveOrderType(CloverClientInterface $client): ?string
    {
        $types = $client->listOrderTypes();
        if (empty($types)) {
            return null;
        }
        foreach ($types as $t) {
            if (isset($t['label']) && stripos((string) $t['label'], 'online') !== false) {
                return $t['id'] ?? null;
            }
        }
        return $types[0]['id'] ?? null;
    }

    /** @return array<int, array> Clover atomic line items, mapped or ad-hoc. */
    private function lineItems(Order $order): array
    {
        $lines = [];
        foreach ($order->items as $item) {
            $qty = max(1, (int) ($item->quantity ?: 1));
            $external = PosItemMap::externalIdFor('clover', (int) $item->item_id);
            $menu = $item->menu;
            $price = (int) round((float) ($menu->price ?? 0) * 100); // Clover uses cents
            if ($external) {
                $lines[] = ['item' => ['id' => $external], 'unitQty' => $qty];
                continue;
            }
            $lines[] = [
                'name'    => $menu->name ?? 'Item',
                'price'   => $price,
                'unitQty' => $qty,
            ];
        }
        if (empty($lines)) {
            $lines[] = ['name' => 'Order #' . $order->id, 'price' => 0, 'unitQty' => 1];
        }
        return $lines;
    }
}
