<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Zomato;




/**
 * PARITY-RUN T7 — Zomato partner client (POS-integration program).
 *
 * We are the POS side of the integration: publish the menu, acknowledge inbound
 * orders inside their accept window, drive the status lifecycle back, and toggle
 * item stock / outlet open-close.
 *
 * Real base URL, auth handshake and endpoint paths are behind Zomato's partner
 * program (BD-ACTIONS #11), so `ZomatoClient` does not exist yet: the factory
 * serves {@see FakeZomatoClient} until credentials land, and every ASSUMED wire
 * field lives in {@see ZomatoPayloadMapper}. This interface IS the seam — a real
 * client only has to implement it.
 */
interface ZomatoClientInterface
{
    /** Exchange partner credentials for a bearer token. */
    public function authenticate(string $apiKey, string $apiSecret): array;

    /** Full-replace menu publish. $payload = ZomatoMenuPusher output. */
    public function publishMenu(array $payload): array;

    /** Acknowledge (accept) an order into the POS, with a prep-time estimate. */
    public function acknowledgeOrder(string $orderId, int $prepMinutes = 20): array;

    /** Reject an order WITH REASON (Zomato relays it to the diner). */
    public function rejectOrder(string $orderId, string $reason): array;

    /** Push a lifecycle status back (see ZomatoProvider::STATUS_MATRIX). */
    public function updateOrderStatus(string $orderId, string $status): array;

    /** 86 / un-86 a single item on the platform. */
    public function setItemAvailability(string $itemCode, bool $available): array;

    /** Outlet open/close (Zomato's "online/offline" toggle). */
    public function setOutletOnline(string $storeId, bool $online): array;

    /** Cheap auth/outlet check for the Test connection button. */
    public function ping(string $storeId): bool;
}
