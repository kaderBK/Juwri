<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators;

use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Juwri\Modules\Channels\Models\AggregatorConnection;

/**
 * T3: first-party / custom delivery integration (key 'custom'). Unlike marketplace
 * providers it has no pre-registered store mapping — the restaurant is named in the
 * payload, but the webhook is only trusted after its HMAC signature is verified with
 * THAT restaurant's secret (Credential::for('custom', storeId)['webhook_secret']).
 * This replaces the old unsigned scaffold that trusted the payload outright.
 */
class CustomDeliveryProvider extends AbstractAggregatorProvider
{
    public function key(): string
    {
        return 'custom';
    }

    public function resolveConnection(Request $request, array $payload): ?AggregatorConnection
    {
        // P1-04: `store_id` or the documented legacy body key.
        $storeId = (int) ($payload['store_id'] ?? $payload['restaurant_id'] ?? 0);
        if (! $storeId || ! Store::whereKey($storeId)->exists()) {
            return null;
        }
        // Ephemeral (unsaved) connection — identity is confirmed by verifyWebhook's
        // signature check against this restaurant's secret before any order is created.
        $conn = new AggregatorConnection([
            'provider' => $this->key(),
            'store_id' => $storeId,
            'branch_id' => $payload['branch_id'] ?? null,
            'external_store_id' => 'custom:'.$storeId,
            'status' => 'active',
        ]);
        $conn->store_id = $storeId; // ensure set for verifyWebhook

        return $conn;
    }

    public function externalStoreId(array $payload): ?string
    {
        $id = $payload['store_id'] ?? $payload['restaurant_id'] ?? null;

        return $id !== null ? 'custom:'.(int) $id : null;
    }

    public function externalOrderId(array $payload): ?string
    {
        $id = $payload['id'] ?? $payload['order_id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    public function mapOrder(array $payload, AggregatorConnection $connection): array
    {
        $items = [];
        foreach ((array) ($payload['items'] ?? []) as $line) {
            $items[] = [
                'item_id' => (int) ($line['item_id'] ?? 0),
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
            ];
        }

        return [
            'type' => 'delivery',
            'phone' => (string) ($payload['customer_phone'] ?? ''),
            'customer_name' => $payload['customer_name'] ?? null,
            'address' => $payload['address'] ?? null,
            'note' => 'Via custom delivery',
            'coupon_code' => $payload['coupon_code'] ?? null,
            'channel_fee' => isset($payload['channel_fee']) ? (float) $payload['channel_fee'] : 0.0,
            'items' => $items,
        ];
    }
}
