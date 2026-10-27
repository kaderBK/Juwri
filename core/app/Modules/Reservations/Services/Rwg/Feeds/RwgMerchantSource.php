<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg\Feeds;

use Juwri\Core\Contracts\Google\GoogleMerchantId;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;

/**
 * GOOGLE-PARTNER Lane C — eligible Reserve-with-Google merchants.
 *
 * ONE canonical merchant set shared by all three feeds so Merchants / Services /
 * Availability never disagree. Each branch is its own Google merchant (owner
 * 2026-10-12): a store that opted in (`rwg_reservations_enabled = '1'`, C7 toggle)
 * publishes one merchant per ELIGIBLE BRANCH — an active branch carrying both
 * coordinates — so every branch matches its own Google listing (own geo, own hours).
 *
 * Geo comes from the branch (Post has no coordinates); RwG requires lat/lng to match
 * a listing, so a store with no geocoded branch is omitted (the dashboard nudges the
 * owner to set branch coordinates + hours). A single-branch store keeps the historic
 * `store-<storeId>` id; a store with two or more eligible branches publishes
 * `store-<storeId>-b<branchId>` per branch (the one GoogleMerchantId rule). The
 * command runs without a tenant context, so every branch lookup is store-scoped.
 */
class RwgMerchantSource
{
    /**
     * One entry per ELIGIBLE BRANCH (see the class docblock). `service_id` is the dining
     * reservation service id namespaced under this merchant.
     *
     * @return list<array{store: Store, branch: Branch, merchant_id: string, service_id: string}>
     */
    public function merchants(): array
    {
        $ids = collect(StoreSettings::storeIdsWhere('rwg_reservations_enabled', fn ($v) => $v === '1')) // legacy string '1'/'0'
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $stores = Store::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'slug', 'title', 'phone', 'google_place_id']);

        $out = [];
        foreach ($stores as $store) {
            $branches = $this->eligibleBranches((int) $store->id);
            if ($branches === []) {
                continue; // no geocoded active branch → cannot publish a valid merchant
            }
            $single = count($branches) === 1; // one branch keeps the historic per-store id
            foreach ($branches as $branch) {
                $out[] = [
                    'store'       => $store,
                    'branch'      => $branch,
                    'merchant_id' => GoogleMerchantId::format((int) $store->id, (int) $branch->id, $single),
                    'service_id'  => $single
                        ? 'dining-' . $store->id
                        : 'dining-' . $store->id . '-b' . $branch->id,
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

    /** Parse a store id out of a `store-{id}` or `store-{id}-b{branchId}` merchant id (booking server). */
    public static function storeIdFromMerchant(string $merchantId): ?int
    {
        return self::parseMerchantId($merchantId)['store'] ?? null;
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
}
