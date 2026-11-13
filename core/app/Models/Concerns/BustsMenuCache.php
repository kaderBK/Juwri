<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models\Concerns;

use App\Services\Storefront\StorefrontMenuCache;

/**
 * P5 — a model whose write should invalidate the cached storefront menu payload for a
 * store. Uses the `boot{TraitName}` convention so it never clobbers a model's own
 * booted()/boot(). The model declares which store it belongs to.
 */
trait BustsMenuCache
{
    protected static function bootBustsMenuCache(): void
    {
        $bust = static function ($model) {
            StorefrontMenuCache::bust($model->menuCacheRestaurantId());
        };

        static::saved($bust);
        static::deleted($bust);
    }

    /** The restaurant (Post) id whose menu cache this row affects, or null. */
    abstract public function menuCacheRestaurantId(): ?int;
}
