<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Modules\Media\Models\MediaAsset;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\MenuReviewMedia;

class MenuReview extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'stars' => 'integer',
        'is_verified' => 'boolean',
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** Photo attachments (join rows), ordered. */
    public function media()
    {
        return $this->hasMany(MenuReviewMedia::class, 'review_id')->orderBy('position');
    }

    /** The MediaAssets attached to this review, via the join table. */
    public function photos()
    {
        return $this->belongsToMany(MediaAsset::class, 'menu_review_media', 'review_id', 'media_asset_id')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /* -------------------------------------------------------------------
     |  Filter scopes  (Verified / Latest / Detailed)
     * ------------------------------------------------------------------- */

    /** Only approved reviews — the public list never shows pending/rejected. */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeForItem($query, int $menuId)
    {
        return $query->where('menu_id', $menuId);
    }

    /** Verified-purchase reviews only. */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /** Detailed = has a body AND at least one photo. */
    public function scopeDetailed($query)
    {
        return $query->whereNotNull('body')
            ->where('body', '!=', '')
            ->whereHas('media');
    }

    /** Apply a named filter. Unknown/empty falls through to "latest". */
    public function scopeApplyFilter($query, ?string $filter)
    {
        return match ($filter) {
            'verified' => $this->scopeVerified($query),
            'detailed' => $this->scopeDetailed($query),
            default => $query, // 'latest' and null — ordering handled by caller
        };
    }
}
