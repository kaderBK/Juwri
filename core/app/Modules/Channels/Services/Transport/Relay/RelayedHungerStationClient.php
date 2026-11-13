<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Transport\Relay;

use Juwri\Modules\Channels\Services\HungerStation\HungerStationClientInterface;

/** HungerStationClientInterface through the juwri Cloud partner relay (generated shape: one relay call per method). */
final class RelayedHungerStationClient extends RelayedClient implements HungerStationClientInterface
{
    public const PARTNER = 'hungerstation';

    /**
     * Fetch a single order's detail.
     *
     * @return array<mixed>
     */
    public function fetchOrder(string $chainId, string $orderId): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Update an order's status (READY_FOR_PICKUP|DISPATCHED|CANCELLED|UPDATE_CART).
     *
     * @param  array<mixed>  $extra
     * @return array<mixed>
     */
    public function updateOrderStatus(string $chainId, string $orderId, string $status, array $extra = []): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * List a vendor's catalog (paginated upstream).
     *
     * @return array<mixed>
     */
    public function listCatalog(string $chainId, string $vendorId): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Update price/active/quantity for one or more products (async job upstream).
     *
     * @param  array<mixed>  $items
     * @return array<mixed>
     */
    public function updateCatalog(string $chainId, string $vendorId, array $items): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Kick off an async full-catalog export (result arrives via webhook upstream).
     *
     * @return array<mixed>
     */
    public function exportCatalog(string $chainId, string $vendorId): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }
}
