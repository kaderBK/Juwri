<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Transport\Relay;

use Juwri\Modules\Channels\Services\PosBridge\Marn\MarnClientInterface;

/** MarnClientInterface through the juwri Cloud partner relay (generated shape: one relay call per method). */
final class RelayedMarnClient extends RelayedClient implements MarnClientInterface
{
    public const PARTNER = 'marn';

    /**
     * Fetch locations: [ ['location_key'=>..,'name'=>..,'currency'=>..], ... ].
     *
     * @return array<mixed>
     */
    public function fetchLocations(): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Fetch products for a location (items/categories/modifiers).
     *
     * @return array<mixed>
     */
    public function fetchLocationProducts(string $locationKey): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Push a PAID order (Pending→Paid). Returns ['order_no'=>..].
     *
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    public function createPaidOrder(array $body): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * Push a HOLD order (no payment yet). Returns ['order_no'=>..].
     *
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    public function createHoldOrder(array $body): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /** Cheap auth/location check for testConnection(). */
    public function ping(string $locationKey): bool
    {
        return (bool) $this->call(__FUNCTION__, func_get_args());
    }
}
