<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Stylist;

/**
 * Pluggable seam for the maison (clothing) theme's AI Style Search + Visual
 * Search. Ship a MOCK now (matches against the store's own catalog); swap to a
 * real provider (Claude vision / a recommender) later by rebinding in
 * AppServiceProvider — no theme/controller change. Both methods return REAL
 * menu ids from the store so results are always buyable products.
 */
interface StylistProvider
{
    /**
     * Text style query -> matching menu ids.
     *
     * @return list<int>
     */
    public function suggest(?string $query, ?int $storeId = null): array;

    /**
     * Uploaded image (base64/data-URL) -> visually-similar menu ids.
     *
     * @return list<int>
     */
    public function visualMatch(?string $imageData, ?int $storeId = null): array;
}
