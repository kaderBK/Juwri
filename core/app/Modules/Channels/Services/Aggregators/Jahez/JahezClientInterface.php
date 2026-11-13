<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Jahez;

/**
 * N5 — Jahez client (leading Saudi delivery aggregator). Base integration-api.jahez.net;
 * auth = API key + Secret Code → token → bearer. Real endpoint paths + payloads are
 * portal-gated (403 without login) so this driver is built from the ecosystem-
 * reconstructed shape — every ASSUMED field lives in JahezPayloadMapper so a
 * certification pass only touches two files. The Fake serves fixtures for CI.
 *
 * DEFERRED-CERTIFICATION: contracts corrected when portal access arrives (BD-ACTIONS #7).
 */
interface JahezClientInterface
{
    /** Exchange API key + secret for a bearer token. */
    public function authenticate(string $apiKey, string $secret): array;

    /** Bulk menu upload (categories + products). $payload = JahezMenuPusher output. */
    public function uploadMenu(array $payload): array;

    /** Accept an order (within the 5-min window). */
    public function acceptOrder(string $orderId): array;

    /** Reject an order WITH REASON (relayed to the customer). */
    public function rejectOrder(string $orderId, string $reason): array;

    /** Push an order status update back to Jahez. */
    public function updateStatus(string $orderId, string $status): array;

    /** Cheap auth/branch check for testConnection. */
    public function ping(string $branchId): bool;
}
