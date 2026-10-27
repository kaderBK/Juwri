<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\SitePage;

/**
 * Publish-time snapshot of a SitePage (insert-only; pruned to last 20 per page
 * by the builder on publish). Restore copies blocks/seo back into the page DRAFT.
 */
class SitePageRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'blocks' => 'array',
        'seo' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<SitePage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(SitePage::class, 'site_page_id');
    }
}
