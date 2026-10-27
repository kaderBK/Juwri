<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\HungerStation;

/**
 * HungerStation Partner API client contract. Two implementations:
 *  - HungerStationClient (real, OAuth2 client-credentials — built in P1),
 *  - FakeHungerStationClient (fixtures — default when no creds / in tests).
 *
 * Every method is chain/vendor-scoped; the caller passes ids resolved from the
 * tenant's BYOK credential, never from client input.
 */
interface HungerStationClientInterface
{
    /** Fetch a single order's detail. */
    public function fetchOrder(string $chainId, string $orderId): array;

    /** Update an order's status (READY_FOR_PICKUP|DISPATCHED|CANCELLED|UPDATE_CART). */
    public function updateOrderStatus(string $chainId, string $orderId, string $status, array $extra = []): array;

    /** List a vendor's catalog (paginated upstream). */
    public function listCatalog(string $chainId, string $vendorId): array;

    /** Update price/active/quantity for one or more products (async job upstream). */
    public function updateCatalog(string $chainId, string $vendorId, array $items): array;

    /** Kick off an async full-catalog export (result arrives via webhook upstream). */
    public function exportCatalog(string $chainId, string $vendorId): array;
}
