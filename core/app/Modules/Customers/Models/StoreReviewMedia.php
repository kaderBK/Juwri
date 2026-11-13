<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Modules\Media\Models\MediaAsset;
use App\Models\StoreReview;

/** DIRECTORY P0-2: photo join row between a store review and a MediaAsset. */
class StoreReviewMedia extends Model
{
    use BelongsToAccount;

    protected $table = 'store_review_media';

    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
    ];

    public function review()
    {
        return $this->belongsTo(StoreReview::class, 'review_id');
    }

    public function asset()
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
