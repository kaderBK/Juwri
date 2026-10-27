<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Foodics;

use App\Models\Order;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeProvider;
use Juwri\Modules\Channels\Services\PosBridge\PosPushResult;
use Throwable;

/**
 * N1-P3 — Foodics POS driver behind the PosBridgeProvider seam.
 *
 * Order push is TWO-STEP and money-safe:
 *   1. POST /orders_calculator — Foodics's engine is authoritative for THEIR tax/rounding;
 *      we store its totals on the push record (provider_meta) for reconciliation and NEVER
 *      overwrite our own order totals.
 *   2. POST /orders with a client-generated UUID derived deterministically from our order
 *      id → a provider-side retry reuses the same id, and a duplicate (422) is treated as
 *      success (natural idempotency).
 *
 * Line items map through pos_item_map (our menu id → Foodics product id). An unmapped
 * item fails the push SOFT with an actionable error — never a silent partial order.
 */
class FoodicsProvider implements PosBridgeProvider
{
    public function key(): string
    {
        return 'foodics';
    }

    public function label(): string
    {
        return 'Foodics';
    }

    public function testConnection(PosBridgeConnection $connection): bool
    {
        try {
            $res = FoodicsClientFactory::make((int) $connection->store_id)->whoami();
            return ! empty($res['data']['id']);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function pushOrder(Order $order, PosBridgeConnection $connection): PosPushResult
    {
        try {
            $client = FoodicsClientFactory::make((int) $order->store_id);

            [$products, $unmapped] = $this->products($order);
            if ($unmapped) {
                // Soft fail: surface exactly which items need mapping; do NOT push a partial order.
                return PosPushResult::failure('unmapped_items: ' . implode(', ', $unmapped));
            }

            $branchId = $this->foodicsBranchId($connection);
            $typeMap = ['on-table' => 1, 'takeaway' => 2, 'delivery' => 3];
            $orderType = $typeMap[$order->type] ?? 2;

            $base = [
                'type'      => $orderType,
                'branch_id' => $branchId,
                'products'  => $products,
                'meta'      => ['reference' => (string) $order->id],
            ];

            // 1) Authoritative totals from Foodics (stored for reconciliation only).
            $calc = $client->calculateOrder($base);
            $providerTotals = $calc['data'] ?? [];

            // 2) Create with a deterministic UUID so a retry is idempotent.
            $uuid = self::deterministicUuid((int) $order->id);
            try {
                $res = $client->createOrder($base + ['id' => $uuid]);
            } catch (FoodicsDuplicateException $e) {
                // 422 dup = already created on a prior attempt → success, id derived from uuid.
                return PosPushResult::success('fo_' . substr(md5($uuid), 0, 10), ['duplicate' => true] + $providerTotals);
            }

            $externalId = (string) ($res['data']['id'] ?? '');
            if ($externalId === '') {
                return PosPushResult::failure('no_order_id', $res);
            }
            return PosPushResult::success($externalId, ['foodics_totals' => $providerTotals]);
        } catch (Throwable $e) {
            return PosPushResult::failure($e->getMessage());
        }
    }

    public function listItems(PosBridgeConnection $connection): array
    {
        try {
            $out = [];
            $page = 1;
            do {
                $res = FoodicsClientFactory::make((int) $connection->store_id)->listProducts($page);
                foreach ($res['data'] ?? [] as $p) {
                    $out[] = ['external_id' => (string) $p['id'], 'name' => $p['name'] ?? ''];
                }
                $last = (int) ($res['meta']['last_page'] ?? 1);
                $page++;
            } while ($page <= $last && $page < 50);
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array{0:array<int,array>,1:array<int,string>} [products, unmappedNames] */
    private function products(Order $order): array
    {
        $products = [];
        $unmapped = [];
        foreach ($order->items as $item) {
            $qty = max(1, (int) ($item->quantity ?: 1));
            $external = PosItemMap::externalIdFor('foodics', (int) $item->item_id);
            if (! $external) {
                $unmapped[] = $item->menu->name ?? ('#' . $item->item_id);
                continue;
            }
            $products[] = [
                'product_id' => $external,
                'quantity'   => $qty,
                'unit_price' => (float) ($item->price ?? $item->menu->price ?? 0),
            ];
        }
        return [$products, $unmapped];
    }

    private function foodicsBranchId(PosBridgeConnection $connection): ?string
    {
        $settings = $connection->settings ?? [];
        return $settings['foodics_branch_id'] ?? $connection->external_location_id ?? null;
    }

    /** Deterministic RFC-4122 v4-shaped UUID from our order id (stable across retries). */
    public static function deterministicUuid(int $orderId): string
    {
        $hash = md5('foodics-order-' . $orderId);
        return sprintf(
            '%s-%s-4%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec(substr($hash, 16, 2)) & 0x3f) | 0x80) . substr($hash, 18, 2),
            substr($hash, 20, 12)
        );
    }
}
