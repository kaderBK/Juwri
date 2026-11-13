<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Google;

/**
 * P5-24 — the one Google merchant-id rule shared by Google Ordering (Storefront) and Reserve with Google (Reservations).
 * Owner 2026-10-12: each branch is its own Google merchant. A one-eligible-branch store keeps `store-<storeId>`;
 * a store with several eligible branches publishes `store-<storeId>-b<branchId>` per branch.
 */
final class GoogleMerchantId
{
    /** @param bool $single true when the store has exactly one eligible branch */
    public static function format(int $storeId, int $branchId, bool $single): string
    {
        return $single ? 'store-'.$storeId : 'store-'.$storeId.'-b'.$branchId;
    }

    /**
     * `store-12` => {store: 12, branch: null}; `store-12-b7` => {store: 12, branch: 7}; anything else null.
     *
     * @return array{store: int, branch: int|null}|null
     */
    public static function parse(string $merchantId): ?array
    {
        if (preg_match('/^store-(\d+)(?:-b(\d+))?$/', $merchantId, $m) !== 1) {
            return null;
        }

        return ['store' => (int) $m[1], 'branch' => isset($m[2]) ? (int) $m[2] : null];
    }
}
