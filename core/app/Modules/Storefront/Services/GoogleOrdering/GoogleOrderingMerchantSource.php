<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\GoogleOrdering;

use Juwri\Core\Contracts\Google\GoogleMerchantId;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;

/**
 * P5-20 — eligible Google Ordering Redirect merchants.
 *
 * ONE canonical store set shared by all three feeds (Merchants / Services / Menu) so they
 * never disagree. A merchant = a STORE that:
 *   1. has `google_ordering_enabled = '1'` (owner opted in; default off), AND
 *   2. has a representative active branch carrying geo coordinates.
 *
 * Geo comes from the branch (a Store has no coordinates); Google matches the merchant to a
 * listing by name / phone / geo / place id, so a store with no geocoded branch is omitted.
 * Each branch is its own Google merchant (owner 2026-10-12): a STORE's eligible branches are its
 * active branches carrying both coordinates. A single-branch store keeps ONE merchant
 * `store-<storeId>` (byte-identical to before); a store with two or more eligible branches
 * publishes one merchant per branch `store-<storeId>-b<branchId>`, so every branch matches its
 * own Google listing (own geo, phone and opening hours). The command runs without a tenant
 * context, so every branch lookup is explicitly scoped to the store id.
 */
final class GoogleOrderingMerchantSource
{
    /**
     * One entry per ELIGIBLE BRANCH (see the class docblock). `service_id` is the merchant's
     * service namespace — the service feed scopes its `order-<kit>` ids under this merchant id.
     *
     * @return list<array{store: Store, branch: Branch, merchant_id: string, service_id: string}>
     */
    public function merchants(): array
    {
        $ids = GoogleOrderingService::storeIds();
        if ($ids === []) {
            return [];
        }

        $stores = Store::query()->with('user')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get(); // whole row: order types, currency and vertical checks read more than id/slug/title

        $out = [];
        foreach ($stores as $store) {
            $branches = $this->eligibleBranches((int) $store->id);
            if ($branches === []) {
                continue; // no geocoded active branch → cannot publish a valid merchant
            }
            $single = count($branches) === 1; // one branch keeps the historic per-store id
            foreach ($branches as $branch) {
                $merchantId = GoogleMerchantId::format((int) $store->id, (int) $branch->id, $single);
                $out[] = [
                    'store'       => $store,
                    'branch'      => $branch,
                    'merchant_id' => $merchantId,
                    'service_id'  => $merchantId,
                ];
            }
        }

        return $out;
    }

    /**
     * Parse a merchant id into its store id and, for a per-branch merchant, the branch id.
     * `store-12` => {store: 12, branch: null}; `store-12-b7` => {store: 12, branch: 7}.
     *
     * @return array{store: int, branch: int|null}|null
     */
    public static function parseMerchantId(string $merchantId): ?array
    {
        return GoogleMerchantId::parse($merchantId);
    }

    /** Parse a store id out of a `store-{id}` merchant id. */
    public static function storeIdFromMerchant(string $merchantId): ?int
    {
        if (preg_match('/^store-(\d+)$/', $merchantId, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * The store's eligible branches — its active branches that carry both coordinates — in id
     * order, so two runs over the same data publish the same merchants (store-scoped).
     *
     * @return list<Branch>
     */
    public function eligibleBranches(int $storeId): array
    {
        return Branch::query()
            ->where('store_id', $storeId)
            ->where('active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** First active branch of the store that carries both coordinates (store-scoped). */
    public function representativeBranch(int $storeId): ?Branch
    {
        return Branch::query()
            ->where('store_id', $storeId)
            ->where('active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->first();
    }
}
