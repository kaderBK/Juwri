<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use App\Models\Order;

/**
 * F0-10 (integrations pack): the single canonical `order.created` webhook payload.
 *
 * order.created is dispatched from four places — the storefront checkout
 * (User\StoreController), the owner order API (OrderApiService), the POS register
 * (PosService) and inbound HungerStation orders (HungerStationOrderService). They
 * had drifted: the storefront sent `{order_id, store_id, table_number, total,
 * currency, status}` while the API/POS sent `{…, type, track_token, source}`, so a
 * Zapier "New Order" Zap sampled on one source broke on another. Because
 * order.created is an OUTBOUND webhook with no internal consumer keyed to a shape,
 * unifying additively is safe — and the keys freeze permanently once a package is
 * published, so every live producer must emit at least the canonical set now.
 *
 * `$extra` is merged last, so a source may ADD a key (the storefront's
 * `table_number`, HungerStation's `hs_order_id`) or override a default, but the
 * eight canonical keys are always present. Matches the F0-5 sample fixture
 * `order.created.json` — WebhookSampleFixturesTest + OrderCreatedWebhookPayloadTest
 * pin the two together.
 */
final class OrderCreatedWebhookPayload
{
    /** The keys every order.created delivery is guaranteed to carry. */
    public const CANONICAL_KEYS = [
        'order_id', 'store_id', 'type', 'status', 'total', 'currency', 'track_token', 'source',
    ];

    /**
     * @param  array<string,mixed>  $extra  source-specific additions/overrides
     * @return array<string,mixed>
     */
    public static function build(Order $order, string $source, array $extra = []): array
    {
        return array_merge([
            'order_id' => $order->id,
            'store_id' => $order->store_id,
            'type' => $order->type,
            'status' => $order->status,
            'total' => $order->total,
            'currency' => $order->currencyCode(), // MC-13: order's stamped currency
            'track_token' => $order->track_token,
            'source' => $source,
        ], $extra);
    }
}
