<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Marn;

/**
 * N4 — Marn client (Saudi cloud POS #2). Real client uses client-credentials tokens
 * (ClientID+ClientSecret → expiring bearer, auto-refresh) against the Marn partner API
 * (UAT marn-partner-uat.azurewebsites.net / prod marn-partner.azurewebsites.net). The
 * Fake serves fixtures for CI — no network.
 *
 * Order push returns an OrderNo we persist on the push record. PAID orders carry payment
 * details; HOLD orders are pushed without payment (checkout completed later).
 */
interface MarnClientInterface
{
    /** Fetch locations: [ ['location_key'=>..,'name'=>..,'currency'=>..], ... ]. */
    public function fetchLocations(): array;

    /** Fetch products for a location (items/categories/modifiers). */
    public function fetchLocationProducts(string $locationKey): array;

    /** Push a PAID order (Pending→Paid). Returns ['order_no'=>..]. */
    public function createPaidOrder(array $body): array;

    /** Push a HOLD order (no payment yet). Returns ['order_no'=>..]. */
    public function createHoldOrder(array $body): array;

    /** Cheap auth/location check for testConnection(). */
    public function ping(string $locationKey): bool;
}
