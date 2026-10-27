<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Marn;

use App\Models\Order;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeProvider;
use Juwri\Modules\Channels\Services\PosBridge\PosPushResult;
use Throwable;

/**
 * N4-P2 — Marn POS driver behind the PosBridgeProvider seam. Pushes our paid order as a
 * Marn PAID order (or HOLD when the connection is set to pay-at-counter), persists the
 * returned OrderNo as the push external id, and maps line items through pos_item_map.
 * Unmapped items fail the push SOFT (never a partial order). Retry idempotency is the
 * PushPosOrderJob's (order_id, provider) unique ledger.
 */
class MarnProvider implements PosBridgeProvider
{
    public function key(): string
    {
        return 'marn';
    }

    public function label(): string
    {
        return 'Marn';
    }

    public function testConnection(PosBridgeConnection $connection): bool
    {
        try {
            return MarnClientFactory::make((int) $connection->store_id)
                ->ping((string) $connection->external_location_id);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function pushOrder(Order $order, PosBridgeConnection $connection): PosPushResult
    {
        try {
            [$products, $unmapped] = $this->products($order);
            if ($unmapped) {
                return PosPushResult::failure('unmapped_items: ' . implode(', ', $unmapped));
            }

            // Deterministic per-order idempotency key: a retry (e.g. after a lost HTTP
            // response) reuses the same key so Marn dedupes instead of creating a second
            // PAID order. The real client sends it as an Idempotency-Key header.
            $idem = self::idempotencyKey((int) $order->id);

            $body = [
                'location_key'    => (string) $connection->external_location_id,
                'brand_key'       => $connection->settings['brand_key'] ?? null,
                'reference'       => (string) $order->id,
                'idempotency_key' => $idem,
                'products'        => $products,
            ];

            $client = MarnClientFactory::make((int) $order->store_id);
            $holdMode = (bool) ($connection->settings['hold_mode'] ?? false);
            try {
                $res = $holdMode ? $client->createHoldOrder($body) : $client->createPaidOrder($body);
            } catch (MarnDuplicateException $e) {
                // Already created on a prior attempt → success, never a duplicate.
                return PosPushResult::success(
                    'marn_' . substr(md5($idem), 0, 10),
                    ['duplicate' => true, 'mode' => $holdMode ? 'hold' : 'paid']
                );
            }

            $orderNo = (string) ($res['order_no'] ?? '');
            if ($orderNo === '') {
                return PosPushResult::failure('no_order_no', $res);
            }
            return PosPushResult::success($orderNo, ['mode' => $holdMode ? 'hold' : 'paid'] + $res);
        } catch (Throwable $e) {
            return PosPushResult::failure($e->getMessage());
        }
    }

    public function listItems(PosBridgeConnection $connection): array
    {
        try {
            $out = [];
            $lk = (string) $connection->external_location_id;
            foreach (MarnClientFactory::make((int) $connection->store_id)->fetchLocationProducts($lk)['data'] ?? [] as $p) {
                $out[] = ['external_id' => (string) ($p['product_key'] ?? ''), 'name' => $p['name'] ?? ''];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Deterministic idempotency key for an order push (stable across retries). */
    public static function idempotencyKey(int $orderId): string
    {
        return 'marn-order-' . $orderId;
    }

    /** @return array{0:array<int,array>,1:array<int,string>} */
    private function products(Order $order): array
    {
        $products = [];
        $unmapped = [];
        foreach ($order->items as $item) {
            $qty = max(1, (int) ($item->quantity ?: 1));
            $external = PosItemMap::externalIdFor('marn', (int) $item->item_id);
            if (! $external) {
                $unmapped[] = $item->menu->name ?? ('#' . $item->item_id);
                continue;
            }
            $products[] = [
                'product_key' => $external,
                'quantity'    => $qty,
                'unit_price'  => (float) ($item->price ?? $item->menu->price ?? 0),
            ];
        }
        return [$products, $unmapped];
    }
}
