<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Swiggy;

/**
 * PARITY-RUN T7 — the SINGLE place every ASSUMED Swiggy field name lives.
 *
 * Swiggy grants partner-API access to APPROVED POS partners only (DECISION D1:
 * scaffold + BYOK now; live onboarding is a BD-gated deferral, BD-ACTIONS #12).
 * The driver reads and writes exclusively through this mapper, so certification
 * touches this file and the client and nothing else.
 *
 * Contract notes for when the real docs land:
 *  - The outlet identifier is Swiggy's `restaurant_id` (a.k.a. `outlet_id`), which
 *    is what an AggregatorConnection stores in `external_store_id`.
 *  - Swiggy's signature header carries a `sha256=` PREFIX; `AbstractAggregator
 *    Provider::normalizeSignature()` already strips it — do not re-implement.
 *  - Money arrives in major units (₹).
 */
class SwiggyPayloadMapper
{
    /** ASSUMED: HMAC-SHA256 signature header — value is `sha256=<hex>`. */
    public const SIGNATURE_HEADER = 'X-Swiggy-Signature';

    /** Credential-vault key holding the shared webhook secret. */
    public const SECRET_KEY = 'webhook_secret';

    /** Item codes we publish in the menu push: `swg_<menuId>`. */
    public const ITEM_CODE_PREFIX = 'swg_';

    /** ASSUMED: outlet id in any inbound event. */
    public static function storeId(array $payload): ?string
    {
        $id = $payload['restaurant_id']
            ?? $payload['outlet_id']
            ?? ($payload['restaurant']['id'] ?? null);

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    /** ASSUMED: external order id — the idempotency key. */
    public static function orderId(array $payload): ?string
    {
        $order = self::order($payload);
        $id = $order['order_id'] ?? $order['id'] ?? $payload['order_id'] ?? $payload['id'] ?? null;

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    /** ASSUMED: event name. `NEW_ORDER` creates, `ORDER_CANCELLED` cancels. */
    public static function event(array $payload): string
    {
        return (string) ($payload['event_type'] ?? $payload['event'] ?? $payload['type'] ?? 'NEW_ORDER');
    }

    public static function isCancellation(array $payload): bool
    {
        return str_contains(strtolower(self::event($payload)), 'cancel');
    }

    public static function order(array $payload): array
    {
        $order = $payload['order'] ?? $payload['order_details'] ?? $payload;

        return is_array($order) ? $order : [];
    }

    /** ASSUMED: order lines. */
    public static function lines(array $payload): array
    {
        $order = self::order($payload);

        return (array) ($order['items'] ?? $order['order_items'] ?? $order['cart'] ?? []);
    }

    public static function lineCode(array $line): string
    {
        return (string) ($line['item_id'] ?? $line['external_item_id'] ?? $line['sku'] ?? $line['code'] ?? '');
    }

    public static function lineQty(array $line): int
    {
        return max(1, (int) ($line['quantity'] ?? $line['qty'] ?? 1));
    }

    public static function lineName(array $line): string
    {
        return (string) ($line['name'] ?? $line['item_name'] ?? self::lineCode($line));
    }

    public static function customer(array $payload): array
    {
        $order = self::order($payload);
        $customer = $order['customer'] ?? $payload['customer'] ?? [];

        return is_array($customer) ? $customer : [];
    }

    public static function address(array $payload): ?string
    {
        $customer = self::customer($payload);
        $order = self::order($payload);
        $address = $customer['address'] ?? $order['delivery_address'] ?? $payload['delivery_address'] ?? null;

        if (is_array($address)) {
            $address = implode(', ', array_filter([
                $address['flat_no'] ?? $address['line1'] ?? null,
                $address['address'] ?? $address['line2'] ?? null,
                $address['city'] ?? null,
            ]));
        }

        return $address !== null && $address !== '' ? (string) $address : null;
    }

    /** ASSUMED: commission in MAJOR units (₹), inside the charges block. */
    public static function channelFee(array $payload): float
    {
        $order = self::order($payload);
        $charges = (array) ($order['charges'] ?? $payload['charges'] ?? []);

        return (float) ($charges['commission']
            ?? $order['commission']
            ?? $payload['commission']
            ?? 0);
    }

    public static function instructions(array $payload): ?string
    {
        $order = self::order($payload);
        $note = $order['instructions'] ?? $order['customer_instructions'] ?? null;

        return $note !== null && $note !== '' ? (string) $note : null;
    }

    /** ASSUMED: `SWIGGY` (their fleet) vs `SELF` (restaurant delivers). */
    public static function deliveryMode(array $payload): string
    {
        $order = self::order($payload);

        return (string) ($order['delivery_type'] ?? $payload['delivery_type'] ?? 'SWIGGY');
    }
}
