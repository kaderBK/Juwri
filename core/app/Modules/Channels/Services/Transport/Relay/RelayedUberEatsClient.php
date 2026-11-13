<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Transport\Relay;

use Juwri\Modules\Channels\Services\Aggregators\UberEats\UberEatsClientInterface;

/** UberEatsClientInterface through the juwri Cloud partner relay (generated shape: one relay call per method). */
final class RelayedUberEatsClient extends RelayedClient implements UberEatsClientInterface
{
    public const PARTNER = 'uber_eats';

    /**
     * Fetch the full order object by its Uber order id.
     *
     * @return array<mixed>
     */
    public function fetchOrder(string $orderId): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /** Accept an order into the POS (must happen within the deadline). */
    public function acceptOrder(string $orderId): void
    {
        $this->call(__FUNCTION__, func_get_args());
    }

    /** Deny an order (out of stock, closed, etc.). */
    public function denyOrder(string $orderId, string $reason = 'STORE_CLOSED'): void
    {
        $this->call(__FUNCTION__, func_get_args());
    }
}
