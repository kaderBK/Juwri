<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Store;

use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;

/**
 * T2-3 (marketplace): store open/closed via the EXISTING branch-hours seam
 * (branches.hours JSON + timezone + accepting_orders + closed_message +
 * branch_hour_exceptions). P6-290: ONE resolver.
 *
 * P1-13 13d: moved down to Core from the Marketplace module — the Community cross-store cart checks it at checkout.
 */
class StoreStatus
{
    /**
     * A store is open when ANY active branch is open (Branch::isOpenNow honours
     * branch_hour_exceptions) AND accepting_orders. No active branches → open
     * = false, branch = null (the caller renders the default closed text).
     *
     * The first branch returned by the query is the "primary" (used for the
     * `hours` snapshot and the optional `branch` key the storefront / view
     * model consult). A store with no active branches has no primary.
     *
     * @return array{open: bool, message: ?string, hours: ?array, branch: ?Branch}
     */
    public static function for(Store $store): array
    {
        $branches = Branch::where('store_id', $store->id)->where('active', true)
            ->orderBy('position')->orderBy('id')->get();

        if ($branches->isEmpty()) {
            return ['open' => false, 'message' => null, 'hours' => null, 'branch' => null];
        }

        $openBranch = $branches->first(fn ($b) => $b->isOpenNow() && (bool) ($b->accepting_orders ?? true));
        $primary = $openBranch ?: $branches->first();

        return [
            'open' => (bool) $openBranch,
            'message' => $openBranch ? null : ($branches->pluck('closed_message')->filter()->first() ?: null),
            'hours' => $primary->hours ?: null,
            'branch' => $primary,
        ];
    }

    public static function isOpen(int $storeId): bool
    {
        $store = Store::find($storeId);

        return $store ? self::for($store)['open'] : false;
    }
}
