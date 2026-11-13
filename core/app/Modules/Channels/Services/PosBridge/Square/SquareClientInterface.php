<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Square;

/**
 * GAPS-RUN-2 B1-P2 Square client. Real client uses OAuth2 tokens (Square-Version
 * pinned); the Fake records calls for CI. Orders API: POST /v2/orders with an
 * idempotency_key; catalog list powers the item-mapping screen.
 */
interface SquareClientInterface
{
    /**
     * Create an order. $body carries idempotency_key + order (location_id,
     * reference_id, line_items, fulfillments). Returns ['id' => <square order id>].
     */
    public function createOrder(array $body): array;

    /** Catalog items for mapping: [ ['external_id'=>..,'name'=>..], ... ]. */
    public function listCatalog(): array;

    /** Cheap auth/location check for testConnection(). */
    public function ping(string $locationId): bool;
}
