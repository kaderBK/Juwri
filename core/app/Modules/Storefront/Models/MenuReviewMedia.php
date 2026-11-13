<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Modules\Media\Models\MediaAsset;
use App\Models\MenuReview;

/**
 * F6 · Join row linking a MenuReview to a media-library MediaAsset (a review photo).
 */
class MenuReviewMedia extends Model
{
    use BelongsToAccount;

    protected $table = 'menu_review_media';

    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
    ];

    public function review()
    {
        return $this->belongsTo(MenuReview::class, 'review_id');
    }

    public function asset()
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
