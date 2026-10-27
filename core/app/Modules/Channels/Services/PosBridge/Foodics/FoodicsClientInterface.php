<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Foodics;

/**
 * N1 — Foodics client (the #1 Saudi POS). Real client uses OAuth2 authorization-code
 * tokens (non-expiring, stored encrypted) against api.foodics.com/v5 (sandbox
 * api-sandbox.foodics.com/v5), signs with a Bearer token, and backs off on 429. The
 * Fake serves fixtures for CI — no network.
 *
 * Order push is TWO-STEP: calculateOrder (their orders_calculator is authoritative for
 * tax/rounding) THEN createOrder with a client UUIDv4 (dup id → 422 = idempotency).
 */
interface FoodicsClientInterface
{
    /** GET /whoami — cheap auth check for testConnection(). */
    public function whoami(): array;

    /** GET /branches — includes receives_online_orders + opening hours. */
    public function listBranches(): array;

    /** GET /products?include=category,modifiers,branches — one page. */
    public function listProducts(int $page = 1): array;

    /** GET /modifiers (modifier groups + options). */
    public function listModifiers(): array;

    /** POST /orders_calculator — authoritative totals; call BEFORE createOrder. */
    public function calculateOrder(array $body): array;

    /** POST /orders — $body carries a client-generated UUIDv4 id (dup → 422). */
    public function createOrder(array $body): array;
}
