<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Skin;

/**
 * Pluggable skin-analysis seam for the lumiere (cosmetic) theme's "Scan Your
 * Face" flow. Ship a MOCK now (plausible metrics + product picks from the
 * store's own catalog); swap to a real provider (e.g. Claude vision) later by
 * rebinding this interface in AppServiceProvider — no theme/controller change.
 */
interface SkinScanProvider
{
    /**
     * Analyze a face image (base64/data-URL, optional for the mock) for a store.
     *
     * @return array{skin_age:int,metrics:array<string,int>,recommendation:string,recommended_menu_ids:list<int>}
     */
    public function analyze(?string $imageData, ?int $storeId = null): array;
}
