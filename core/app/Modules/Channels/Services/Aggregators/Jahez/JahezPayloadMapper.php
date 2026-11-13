<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Jahez;

/**
 * N5 — the SINGLE place every ASSUMED Jahez field name lives. Jahez's real payload
 * contracts are portal-gated, so the driver reads/writes through this mapper; when
 * certification reveals the true field names, only THIS file (and the client) changes.
 *
 * Sources: reconstructed 2026-07-12 from third-party connectors (Odoo module, GetOrder,
 * Foodics help). Treat every accessor as ASSUMED until certification (BD-ACTIONS #7).
 */
class JahezPayloadMapper
{
    /** ASSUMED: external store/branch id in an inbound order webhook. */
    public static function storeId(array $payload): ?string
    {
        $id = $payload['branch_id'] ?? $payload['store_id'] ?? $payload['branchId'] ?? null;
        return $id !== null ? (string) $id : null;
    }

    /** ASSUMED: external order id (idempotency key). */
    public static function orderId(array $payload): ?string
    {
        $id = $payload['jahez_order_id'] ?? $payload['order_id'] ?? $payload['id'] ?? null;
        return $id !== null ? (string) $id : null;
    }

    /** ASSUMED: order line items with product code + quantity. */
    public static function lines(array $payload): array
    {
        $order = $payload['order'] ?? $payload;
        return (array) ($order['products'] ?? $order['items'] ?? []);
    }

    /** ASSUMED: a line's product code (maps to our menu id via OpenItemCode). */
    public static function lineCode(array $line): string
    {
        return (string) ($line['product_id'] ?? $line['sku'] ?? $line['code'] ?? '');
    }

    public static function lineQty(array $line): int
    {
        return max(1, (int) ($line['quantity'] ?? $line['qty'] ?? 1));
    }

    public static function customer(array $payload): array
    {
        $order = $payload['order'] ?? $payload;
        return (array) ($order['customer'] ?? $payload['customer'] ?? []);
    }

    public static function channelFee(array $payload): float
    {
        $order = $payload['order'] ?? $payload;
        return (float) ($order['commission'] ?? $order['channel_fee'] ?? $payload['commission'] ?? 0);
    }

    /** ASSUMED: signature header for webhook verification (shared-secret until portal). */
    public const SIGNATURE_HEADER = 'X-Jahez-Signature';
    /** ASSUMED: credential key holding the shared webhook secret. */
    public const SECRET_KEY = 'webhook_secret';
}
