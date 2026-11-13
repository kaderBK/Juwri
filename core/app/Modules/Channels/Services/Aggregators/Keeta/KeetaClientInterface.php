<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Keeta;

/**
 * N2 — Keeta (Meituan international) client. Real client uses a 90-day access token
 * (+ refresh token, refreshed 3–5 days early, 60-min grace, refresh rate-limited
 * 1/min) and signs every request HMAC-SHA256 (see KeetaSigner). The Fake serves
 * fixtures for CI — no network.
 *
 * We are the POS side: push the full menu (full-replace), then drive order lifecycle.
 */
interface KeetaClientInterface
{
    /** Full-replace menu push. $payload = KeetaMenuPusher output. Returns provider ack. */
    public function merchantUpdate(array $payload): array;

    /** Accept a new order into the POS (within the 5-min window). */
    public function confirmOrder(string $orderId): array;

    /** Reject / cancel an order. */
    public function cancelOrder(string $orderId, string $reason = ''): array;

    /** Mark food ready → triggers Keeta rider dispatch. */
    public function prepareOrder(string $orderId): array;

    /** Handover to rider. */
    public function collectOrder(string $orderId): array;

    /** Approve a customer refund request. */
    public function agreeRefund(string $orderId): array;

    /** Decline a customer refund request. */
    public function rejectRefund(string $orderId, string $reason = ''): array;

    /** Cheap token/store check for testConnection. */
    public function ping(string $storeId): bool;
}
