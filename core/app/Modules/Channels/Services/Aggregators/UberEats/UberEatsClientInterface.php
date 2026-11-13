<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\UberEats;

/**
 * T3-P3 Uber Eats Marketplace client. The webhook only carries a pointer, so we GET
 * the full order; and we must accept/deny within 11.5 min. Real client uses OAuth2
 * client-credentials (30-day tokens, cached); the Fake serves fixtures for CI.
 */
interface UberEatsClientInterface
{
    /** Fetch the full order object by its Uber order id. */
    public function fetchOrder(string $orderId): array;

    /** Accept an order into the POS (must happen within the deadline). */
    public function acceptOrder(string $orderId): void;

    /** Deny an order (out of stock, closed, etc.). */
    public function denyOrder(string $orderId, string $reason = 'STORE_CLOSED'): void;
}
