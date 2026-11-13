<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Swiggy;




/**
 * PARITY-RUN T7 — Swiggy partner client.
 *
 * We are the POS side: publish the menu, confirm inbound orders, drive the status
 * lifecycle back, and toggle item stock / outlet on-off.
 *
 * Swiggy's partner APIs are granted to APPROVED POS partners, so `SwiggyClient`
 * does not exist yet (BD-ACTIONS #12): the factory serves {@see FakeSwiggyClient}
 * until onboarding completes, and every ASSUMED wire field lives in
 * {@see SwiggyPayloadMapper}. This interface IS the seam.
 */
interface SwiggyClientInterface
{
    /** Exchange partner credentials for a bearer token. */
    public function authenticate(string $clientId, string $clientSecret): array;

    /** Full-replace menu publish. $payload = SwiggyMenuPusher output. */
    public function publishMenu(array $payload): array;

    /** Confirm an order into the POS with a prep-time estimate. */
    public function confirmOrder(string $orderId, int $prepMinutes = 20): array;

    /** Reject an order WITH REASON. */
    public function rejectOrder(string $orderId, string $reason): array;

    /** Push a lifecycle status back (see SwiggyProvider::STATUS_MATRIX). */
    public function updateOrderStatus(string $orderId, string $status): array;

    /** Toggle a single item's stock on the platform. */
    public function setItemAvailability(string $itemCode, bool $available): array;

    /** Outlet on/off (Swiggy's "toggle outlet" control). */
    public function setOutletOnline(string $storeId, bool $online): array;

    /** Cheap auth/outlet check for the Test connection button. */
    public function ping(string $storeId): bool;
}
