<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Transport\Relay;

use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaClientInterface;

/** KeetaClientInterface through the juwri Cloud partner relay (generated shape: one relay call per method). */
final class RelayedKeetaClient extends RelayedClient implements KeetaClientInterface
{
    public const PARTNER = 'keeta';

    /**
     * Full-replace menu push. $payload = KeetaMenuPusher output. Returns provider ack.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function merchantUpdate(array $payload): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Accept a new order into the POS (within the 5-min window).
     *
     * @return array<mixed>
     */
    public function confirmOrder(string $orderId): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Reject / cancel an order.
     *
     * @return array<mixed>
     */
    public function cancelOrder(string $orderId, string $reason = ''): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Mark food ready → triggers Keeta rider dispatch.
     *
     * @return array<mixed>
     */
    public function prepareOrder(string $orderId): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Handover to rider.
     *
     * @return array<mixed>
     */
    public function collectOrder(string $orderId): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Approve a customer refund request.
     *
     * @return array<mixed>
     */
    public function agreeRefund(string $orderId): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Decline a customer refund request.
     *
     * @return array<mixed>
     */
    public function rejectRefund(string $orderId, string $reason = ''): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /** Cheap token/store check for testConnection. */
    public function ping(string $storeId): bool
    {
        return (bool) $this->call(__FUNCTION__, func_get_args());
    }
}
