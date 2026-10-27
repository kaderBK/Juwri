<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Juwri\Core\Models\Store;

/**
 * GAPS-RUN-2: the tenant-scoping guard every owner dashboard controller shares.
 * Resolves the restaurant the request is acting on (query ?store_id, else the
 * session-current restaurant, else the first the owner controls) and aborts 403 if
 * it is not one the owner controls — so the authorization rule lives in ONE place.
 */
trait ResolvesCurrentStore
{
    /**
     * The restaurant ids this owner controls.
     *
     * @return list<int>
     */
    protected function storeIds(): array
    {
        return dashboard_store_ids();
    }

    /** The current restaurant id, authorized against the owner's set (403 otherwise). */
    protected function currentRestaurantId(Request $request): int
    {
        $ids = $this->storeIds();
        $rid = (int) ($request->query('store_id') ?: (dashboard_current_store()->id ?? $ids[0] ?? 0));
        abort_unless(in_array($rid, $ids, true), 403);

        return $rid;
    }

    /** The current restaurant model (same authorization). */
    protected function currentRestaurant(Request $request): Store
    {
        return Store::findOrFail($this->currentRestaurantId($request));
    }
}
