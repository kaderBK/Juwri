<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Jahez;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use App\Models\Order;
use Juwri\Modules\Channels\Services\Aggregators\AbstractAggregatorProvider;

/**
 * N5 — Jahez delivery driver behind the shared AggregatorProvider seam. MIRRORS Keeta
 * (N2). Webhook auth is a shared-secret HMAC (ASSUMED until portal access — see
 * JahezPayloadMapper). Orders map via OpenItemCode straight to our menu ids; auto-accept
 * on ingest within the 5-min window (orders older than 5 min cannot be accepted), unless
 * the connection is set to manual accept. Reject-with-reason + status updates outbound.
 *
 * DEFERRED-CERTIFICATION: every payload contract is ASSUMED and isolated in the mapper.
 */
class JahezProvider extends AbstractAggregatorProvider
{
    protected string $signatureHeader = JahezPayloadMapper::SIGNATURE_HEADER;
    protected string $secretKey = JahezPayloadMapper::SECRET_KEY;

    public function key(): string
    {
        return 'jahez';
    }

    public function externalStoreId(array $payload): ?string
    {
        return JahezPayloadMapper::storeId($payload);
    }

    public function externalOrderId(array $payload): ?string
    {
        return JahezPayloadMapper::orderId($payload);
    }

    public function mapOrder(array $payload, AggregatorConnection $connection): array
    {
        $items = [];
        $unmapped = [];
        foreach (JahezPayloadMapper::lines($payload) as $line) {
            $code = JahezPayloadMapper::lineCode($line);
            $qty = JahezPayloadMapper::lineQty($line);
            $menuId = preg_match('/(\d+)$/', $code, $m) ? (int) $m[1] : null;
            if ($menuId) {
                $items[] = ['item_id' => $menuId, 'quantity' => $qty];
            } else {
                $unmapped[] = $qty . '× ' . ($line['name'] ?? $code);
            }
        }

        $note = 'Via Jahez';
        if ($unmapped) {
            $note .= ' · ' . __('Unmapped items (review)') . ': ' . implode(', ', $unmapped);
        }

        $customer = JahezPayloadMapper::customer($payload);
        return [
            'type'          => 'delivery',
            'customer_name' => $customer['name'] ?? null,
            'phone'         => (string) ($customer['phone'] ?? $customer['mobile'] ?? ''),
            'address'       => $customer['address'] ?? null,
            'note'          => $note,
            'channel_fee'   => JahezPayloadMapper::channelFee($payload),
            'items'         => $items,
        ];
    }

    /** Auto-accept within the 5-min window unless manual-accept is set. Best-effort. */
    public function afterIngest(Order $order, AggregatorConnection $connection, array $payload): void
    {
        if (($connection->auto_accept ?? 1) == 0) {
            return;
        }
        $externalId = $this->externalOrderId($payload);
        if ($externalId) {
            JahezClientFactory::make((int) $connection->store_id)->acceptOrder($externalId);
        }
    }

    public function pushStatus(Order $order, AggregatorConnection $connection): void
    {
        $externalId = \Juwri\Modules\Channels\Models\DeliveryOrder::where('provider', 'jahez')
            ->where('order_id', $order->id)->value('external_id');
        if (! $externalId) {
            return;
        }
        JahezClientFactory::make((int) $connection->store_id)
            ->updateStatus($externalId, (string) ($order->status ?? ''));
    }
}
