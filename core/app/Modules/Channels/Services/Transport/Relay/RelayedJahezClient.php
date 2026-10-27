<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Transport\Relay;

use Juwri\Modules\Channels\Services\Aggregators\Jahez\JahezClientInterface;

/** JahezClientInterface through the juwri Cloud partner relay (generated shape: one relay call per method). */
final class RelayedJahezClient extends RelayedClient implements JahezClientInterface
{
    public const PARTNER = 'jahez';

    /**
     * Exchange API key + secret for a bearer token.
     *
     * @return array<mixed>
     */
    public function authenticate(string $apiKey, string $secret): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Bulk menu upload (categories + products). $payload = JahezMenuPusher output.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function uploadMenu(array $payload): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Accept an order (within the 5-min window).
     *
     * @return array<mixed>
     */
    public function acceptOrder(string $orderId): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Reject an order WITH REASON (relayed to the customer).
     *
     * @return array<mixed>
     */
    public function rejectOrder(string $orderId, string $reason): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Push an order status update back to Jahez.
     *
     * @return array<mixed>
     */
    public function updateStatus(string $orderId, string $status): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /** Cheap auth/branch check for testConnection. */
    public function ping(string $branchId): bool
    {
        return (bool) $this->call(__FUNCTION__, func_get_args());
    }
}
