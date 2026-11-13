<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Keeta;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Core\Models\Credential;
use App\Models\Order;
use Juwri\Modules\Channels\Services\Aggregators\AbstractAggregatorProvider;
use Illuminate\Http\Request;

/**
 * N2-P3 — Keeta delivery driver behind the shared AggregatorProvider seam.
 *
 * Inbound: Event 1001 New Order → AggregatorIngestor maps items via OpenItemCode
 * (sku_<menuId> straight back to our Menu id) and creates the Order through the ONE
 * creation path. Webhook signature is HMAC-SHA256 over METHOD/PATH/query/body with the
 * tenant's app_secret (KeetaSigner) — unsigned/bad → 401, no side effects. Replays are
 * idempotent via the delivery_orders (provider, external_id) unique index.
 *
 * Accept window: Keeta retries 3× at 1-min intervals if not acked within 5 min. We
 * auto-confirm on a successful ingest (afterIngest) unless the connection is set to
 * manual accept. Outbound lifecycle (prepare/collect/cancel) via pushStatus.
 */
class KeetaProvider extends AbstractAggregatorProvider
{
    protected string $signatureHeader = 'X-Keeta-Signature';

    public function key(): string
    {
        return 'keeta';
    }

    public function externalStoreId(array $payload): ?string
    {
        $id = $payload['storeId'] ?? $payload['shopId'] ?? $payload['shop']['code'] ?? null;
        return $id !== null ? (string) $id : null;
    }

    public function externalOrderId(array $payload): ?string
    {
        $id = $payload['orderId'] ?? $payload['order']['id'] ?? $payload['id'] ?? null;
        return $id !== null ? (string) $id : null;
    }

    /** Verify with Keeta's request signing (over method/path/query/raw body). */
    public function verifyWebhook(Request $request, AggregatorConnection $connection): bool
    {
        $secret = $this->appSecret($connection);
        if ($secret === null || $secret === '') {
            return false;
        }
        $provided = (string) $request->header($this->signatureHeader, '');
        return KeetaSigner::verify(
            $secret,
            $request->getMethod(),
            $request->getPathInfo(),
            $request->query(),
            $request->getContent(),
            $provided
        );
    }

    public function mapOrder(array $payload, AggregatorConnection $connection): array
    {
        $order = $payload['order'] ?? $payload;
        $lines = $order['items'] ?? $order['skus'] ?? [];

        $items = [];
        $unmapped = [];
        foreach ((array) $lines as $line) {
            $code = (string) ($line['skuCode'] ?? $line['code'] ?? $line['sku'] ?? '');
            $qty = max(1, (int) ($line['quantity'] ?? $line['qty'] ?? 1));
            $menuId = $this->menuIdFromCode($code);
            if ($menuId) {
                $items[] = ['item_id' => $menuId, 'quantity' => $qty];
            } else {
                $unmapped[] = $qty . '× ' . ($line['name'] ?? $code);
            }
        }

        $note = 'Via Keeta';
        if ($unmapped) {
            $note .= ' · ' . __('Unmapped items (review)') . ': ' . implode(', ', $unmapped);
        }

        $customer = $order['customer'] ?? $payload['customer'] ?? [];
        $fee = $order['commission'] ?? $order['channelFee'] ?? $payload['channelFee'] ?? 0;

        return [
            'type'          => 'delivery',
            'customer_name' => $customer['name'] ?? null,
            'phone'         => (string) ($customer['phone'] ?? $customer['phoneNumber'] ?? ''),
            'address'       => $order['deliveryAddress']['line1'] ?? ($order['address'] ?? null),
            'note'          => $note,
            'channel_fee'   => (float) $fee,
            'items'         => $items,
        ];
    }

    /** OpenItemCode → our Menu id: 'sku_123' | 'spu_123' | '123' → 123. */
    private function menuIdFromCode(string $code): ?int
    {
        if ($code === '') {
            return null;
        }
        if (preg_match('/(\d+)$/', $code, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Auto-confirm the order into Keeta on a successful ingest (5-min accept window),
     * unless the connection is set to manual accept (owner accepts at KDS). Best-effort.
     */
    public function afterIngest(Order $order, AggregatorConnection $connection, array $payload): void
    {
        if (($connection->auto_accept ?? 1) == 0) {
            return; // manual accept: KDS countdown handles it
        }
        $externalId = $this->externalOrderId($payload);
        if ($externalId) {
            KeetaClientFactory::make((int) $connection->store_id)->confirmOrder($externalId);
        }
    }

    /** Map our order status → Keeta lifecycle calls. Best-effort, never throws out. */
    public function pushStatus(Order $order, AggregatorConnection $connection): void
    {
        $externalId = \Juwri\Modules\Channels\Models\DeliveryOrder::where('provider', 'keeta')
            ->where('order_id', $order->id)->value('external_id');
        if (! $externalId) {
            return;
        }
        $client = KeetaClientFactory::make((int) $connection->store_id);
        $status = (string) ($order->status ?? '');

        match ($status) {
            'preparing', 'ready'  => $client->prepareOrder($externalId),
            'out_for_delivery', 'picked_up', 'completed' => $client->collectOrder($externalId),
            'cancelled', 'declined' => $client->cancelOrder($externalId),
            default => null,
        };
    }

    private function appSecret(AggregatorConnection $connection): ?string
    {
        $creds = Credential::for('keeta', (int) $connection->store_id) ?? [];
        return $creds['app_secret'] ?? $creds['webhook_secret'] ?? null;
    }
}
