<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Services\Aggregators\UberEats\UberEatsClientFactory;
use Juwri\Modules\Menu\Models\Menu;

/**
 * T3-P3 Uber Eats Marketplace direct integration. Uber sends ONE webhook per app with
 * all events multiplexed; `orders.notification` carries only a pointer, so we GET the
 * full order via the BYOK client, map items by SKU, and must accept/deny within 11.5
 * min (we auto-accept on successful ingest). Signature = X-Uber-Signature = hex
 * HMAC-SHA256(raw body, client secret). Production access needs Uber approval (deferred).
 */
class UberEatsProvider extends AbstractAggregatorProvider
{
    protected string $signatureHeader = 'X-Uber-Signature';

    protected string $secretKey = 'client_secret';

    /** The fetched order is cached per-request so mapOrder doesn't double-fetch. */
    private array $fetchedOrders = [];

    public function key(): string
    {
        return 'uber_eats';
    }

    public function externalStoreId(array $payload): ?string
    {
        // Notification carries the store id in meta/resource; fall back to a nested field.
        $id = $payload['meta']['store_id']
            ?? $payload['store_id']
            ?? ($payload['resource_href'] ?? null);

        return $id !== null ? (string) $id : null;
    }

    public function externalOrderId(array $payload): ?string
    {
        $id = $payload['meta']['resource_id'] ?? $payload['resource_id'] ?? $payload['order_id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    public function mapOrder(array $payload, AggregatorConnection $connection): array
    {
        $orderId = $this->externalOrderId($payload);
        $client = UberEatsClientFactory::make((int) $connection->store_id);
        $order = $this->fetchedOrders[$orderId] ??= $client->fetchOrder((string) $orderId);

        $items = [];
        $unmapped = [];
        foreach ((array) ($order['cart']['items'] ?? []) as $line) {
            $sku = (string) ($line['external_data'] ?? $line['sku'] ?? $line['id'] ?? '');
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $menu = $this->resolveMenu((int) $connection->store_id, $sku);
            if ($menu) {
                $items[] = ['item_id' => $menu->id, 'quantity' => $qty];
            } else {
                $unmapped[] = $qty.'× '.($line['title'] ?? $sku);
            }
        }

        $note = 'Via Uber Eats';
        if ($unmapped) {
            $note .= ' · '.__('Unmapped items (review)').': '.implode(', ', $unmapped);
        }

        $customer = $order['customer'] ?? [];
        $totalCents = (int) ($order['payment']['charges']['total']['amount'] ?? 0);

        return [
            'type' => 'delivery',
            'customer_name' => $customer['name'] ?? null,
            'phone' => (string) ($customer['phone'] ?? ''),
            'note' => $note,
            'channel_fee' => 0.0, // Uber commission reconciled from payout reports (not per-order here)
            'items' => $items,
        ];
    }

    private function resolveMenu(int $storeId, string $sku): ?Menu
    {
        if ($sku === '') {
            return null;
        }
        $q = Menu::where('store_id', $storeId);
        /* WV.V — the table is `menu`, SINGULAR (see DeliverectProvider). The old
           `hasColumn('menus', …)` named a table that does not exist, so the branch
           was dead and Uber Eats SKUs only ever resolved as bare numeric ids. */
        if (Schema::hasColumn(new Menu()->getTable(), 'external_sku')) {
            $byExt = (clone $q)->where('external_sku', $sku)->first();
            if ($byExt) {
                return $byExt;
            }
        }

        return ctype_digit($sku) ? (clone $q)->find((int) $sku) : null;
    }

    /** After a successful ingest, accept the order into the POS (within the deadline). */
    public function afterIngest(Order $order, AggregatorConnection $connection, array $payload): void
    {
        $orderId = $this->externalOrderId($payload);
        if ($orderId) {
            UberEatsClientFactory::make((int) $connection->store_id)->acceptOrder($orderId);
        }
    }
}
