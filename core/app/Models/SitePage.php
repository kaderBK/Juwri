<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Modules\Content\Models\SitePageRevision;

/**
 * A block-composed platform page. type=home (one per site, slug NULL) |
 * type=page (slug-addressed). `blocks` = live draft; `published_blocks` =
 * publish snapshot, the ONLY document the public renderer reads. Localized
 * fields (title/block content/seo) are per-locale JSON; fallback/resolution is
 * done ONLY by Juwri\Core\Support\LocalizedContent — never re-implemented.
 */
class SitePage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'title' => 'array',
        'blocks' => 'array',
        'published_blocks' => 'array',
        'seo' => 'array',
        'show_in_footer' => 'boolean',
        'sort' => 'integer',
        'published_at' => 'datetime',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /** @return HasMany<SitePageRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(SitePageRevision::class, 'site_page_id');
    }

    /**
     * Explicit site scoping — cross-site queries must always pass through here.
     *
     * @param  Builder<self>  $q
     * @return Builder<self>
     */
    public function scopeForSite(Builder $q, int $siteId): Builder
    {
        return $q->where('site_id', $siteId);
    }

    /**
     * @param  Builder<self>  $q
     * @return Builder<self>
     */
    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', 'published');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
