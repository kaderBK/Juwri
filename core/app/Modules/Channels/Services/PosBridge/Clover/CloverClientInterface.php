<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Clover;

/**
 * GAPS-RUN-2 B1-P3 Clover client. External orders go through the atomic-order path:
 * POST /v3/merchants/{mId}/atomic_order/orders — one call with a merchant-specific
 * orderType id + line items mapped to Clover inventory ids. Real client uses OAuth v2
 * (expiring access+refresh tokens); the Fake records calls for CI.
 */
interface CloverClientInterface
{
    /**
     * Create an atomic order. $payload = ['orderCart' => ['orderType'=>['id'=>..],
     * 'lineItems'=>[...]]] plus an idempotency key header value. Returns
     * ['id' => <clover order id>].
     */
    public function createAtomicOrder(array $payload, string $idempotencyKey): array;

    /** Merchant orderTypes (cached once) — used to pick the external-order type id. */
    public function listOrderTypes(): array;

    /** Inventory items (expand=categories,modifierGroups) for the mapping screen. */
    public function listInventory(): array;

    /** Cheap auth/merchant check for testConnection(). */
    public function ping(string $merchantId): bool;
}
