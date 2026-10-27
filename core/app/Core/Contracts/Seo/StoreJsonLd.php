<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Seo;

use Juwri\Core\Models\Store;

/**
 * P1-12: a store's schema.org Restaurant JSON-LD (AEO/SEO). Implemented by the Storefront module (StoreJsonLdBuilder);
 * other modules on the same layer (the Marketplace store page) ask for it through this contract instead of importing
 * Storefront.
 */
interface StoreJsonLd
{
    /** @return array<string, mixed> the JSON-LD object, empty fields left out */
    public function schemaRestaurant(Store $store): array;
}
