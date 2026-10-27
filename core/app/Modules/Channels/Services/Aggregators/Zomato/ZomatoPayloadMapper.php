<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Zomato;

/**
 * PARITY-RUN T7 — the SINGLE place every ASSUMED Zomato field name lives.
 *
 * Zomato's POS-integration contracts are behind their developer/partner program
 * (DECISION D1: scaffold + BYOK now, live credentials are BD-gated). So the driver
 * reads and writes exclusively through this mapper: when certification reveals the
 * true field names, only THIS file and the client change — the provider, the
 * ingest pipeline and every test stay put.
 *
 * Sources: reconstructed 2026-07-22 from Zomato's public POS-integration overview
 * plus third-party connector documentation (UrbanPiper-class hubs, POS vendors).
 * Treat EVERY accessor below as ASSUMED until certification (BD-ACTIONS #11).
 *
 * Contract notes worth keeping when the real docs land:
 *  - `res_id` is Zomato's outlet identifier and is what an AggregatorConnection
 *    stores in `external_store_id`.
 *  - Money arrives in major units (₹), unlike Deliverect's minor units.
 */
class ZomatoPayloadMapper
{
    /** ASSUMED: HMAC-SHA256 signature header (hex, no prefix). */
    public const SIGNATURE_HEADER = 'X-Zomato-Signature';

    /** Credential-vault key holding the shared webhook secret. */
    public const SECRET_KEY = 'webhook_secret';

    /** Item codes we publish in the menu push: `zom_<menuId>`. */
    public const ITEM_CODE_PREFIX = 'zom_';

    /** ASSUMED: outlet id in any inbound event. */
    public static function storeId(array $payload): ?string
    {
        $id = $payload['res_id']
            ?? $payload['restaurant_id']
            ?? $payload['outlet_id']
            ?? ($payload['restaurant']['res_id'] ?? null);

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    /** ASSUMED: external order id — the idempotency key. */
    public static function orderId(array $payload): ?string
    {
        $order = self::order($payload);
        $id = $order['order_id'] ?? $order['id'] ?? $payload['order_id'] ?? $payload['id'] ?? null;

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    /** ASSUMED: event name. `order.placed` creates, `order.cancelled` cancels. */
    public static function event(array $payload): string
    {
        return (string) ($payload['event'] ?? $payload['event_type'] ?? $payload['type'] ?? 'order.placed');
    }

    public static function isCancellation(array $payload): bool
    {
        $event = strtolower(self::event($payload));

        return str_contains($event, 'cancel');
    }

    /** The order envelope — Zomato nests it, but tolerate a flat body. */
    public static function order(array $payload): array
    {
        $order = $payload['order'] ?? $payload['order_details'] ?? $payload;

        return is_array($order) ? $order : [];
    }

    /** ASSUMED: order lines. */
    public static function lines(array $payload): array
    {
        $order = self::order($payload);

        return (array) ($order['dishes'] ?? $order['items'] ?? $order['order_items'] ?? []);
    }

    /** ASSUMED: a line's item code — resolved through IndiaItemResolver. */
    public static function lineCode(array $line): string
    {
        return (string) ($line['dish_id'] ?? $line['item_id'] ?? $line['sku'] ?? $line['code'] ?? '');
    }

    public static function lineQty(array $line): int
    {
        return max(1, (int) ($line['quantity'] ?? $line['qty'] ?? 1));
    }

    public static function lineName(array $line): string
    {
        return (string) ($line['name'] ?? $line['dish_name'] ?? self::lineCode($line));
    }

    public static function customer(array $payload): array
    {
        $order = self::order($payload);
        $customer = $order['customer'] ?? $payload['customer'] ?? [];

        return is_array($customer) ? $customer : [];
    }

    /** ASSUMED: full delivery address as one line. */
    public static function address(array $payload): ?string
    {
        $customer = self::customer($payload);
        $order = self::order($payload);
        $address = $customer['address'] ?? $order['delivery_address'] ?? $payload['delivery_address'] ?? null;

        if (is_array($address)) {
            $address = implode(', ', array_filter([
                $address['line1'] ?? null,
                $address['line2'] ?? null,
                $address['city'] ?? null,
            ]));
        }

        return $address !== null && $address !== '' ? (string) $address : null;
    }

    /** ASSUMED: rider mode — `zomato` (their fleet) vs `self` (restaurant delivers). */
    public static function deliveryMode(array $payload): string
    {
        $order = self::order($payload);

        return (string) ($order['delivery_mode'] ?? $payload['delivery_mode'] ?? 'zomato');
    }

    /** ASSUMED: commission in MAJOR units (₹). */
    public static function channelFee(array $payload): float
    {
        $order = self::order($payload);

        return (float) ($order['commission'] ?? $order['commission_amount'] ?? $payload['commission'] ?? 0);
    }

    /** ASSUMED: free-text cooking instructions from the diner. */
    public static function instructions(array $payload): ?string
    {
        $order = self::order($payload);
        $note = $order['instructions'] ?? $order['cooking_instructions'] ?? null;

        return $note !== null && $note !== '' ? (string) $note : null;
    }
}
