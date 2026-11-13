<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * A platform site (brand). In the 4-separate-installs topology each install
 * seeds exactly ONE active row; `domains` is dormant until host consolidation
 * (HP-63). Resolution lives ONLY in Juwri\Modules\Content\Services\SitePages\SiteContext —
 * exact host match on `domains`, else the single active row.
 */
class Site extends Model
{
    protected $guarded = [];

    protected $casts = [
        'domains' => 'array',
        'locales' => 'array',
        'theme' => 'array',
        'settings' => 'array',
        'active' => 'boolean',
    ];

    /** @return HasMany<SitePage, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(SitePage::class, 'site_id');
    }

    /**
     * @param  Builder<self>  $q
     * @return Builder<self>
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    protected static function booted(): void
    {
        // Bust SiteContext's resolution caches whenever a site row changes
        // (mirrors Reseller::resolveForRequest cache mechanics). Key scheme is
        // owned by SiteContext: site:host:{host} per domain + site:default.
        $bust = function (self $site): void {
            Cache::forget('site:default');
            foreach ((array) ($site->domains ?? []) as $host) {
                if (is_string($host) && $host !== '') {
                    Cache::forget('site:host:'.strtolower($host));
                }
            }
        };
        static::saved($bust);
        static::deleted($bust);
    }
}
