<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use App\Services\FeatureRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * P1 (feature-platform): one row per catalog feature — the merged single
 * source of truth over the three legacy catalogs. FeatureRegistry reads this
 * (with its PHP array as fallback when the table is empty).
 */
class Feature extends Model
{
    protected $guarded = [];

    /** P6-58: any catalogue write drops the cross-request FeatureRegistry cache. */
    protected static function booted(): void
    {
        $bust = static function (): void {
            FeatureRegistry::clearCache();
        };
        static::saved($bust);
        static::deleted($bust);
    }

    protected $casts = [
        'sellable_via' => 'array',
        'platform_only' => 'boolean',
        'killable' => 'boolean',
        'shipped' => 'boolean',
    ];
}
