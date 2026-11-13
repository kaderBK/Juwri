<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Models;

use App\Models\StoreReview;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * GOOGLE-PARTNER Lane B: a cached Google Business Profile review. DISTINCT from
 * {@see StoreReview} (platform-native) — no code path ever reads or writes both;
 * the inbox UI labels these "Google reviews". Upserted by PullGbpReviewsJob on the
 * natural key [location_id, external_id].
 */
class GbpReview extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'reviewer_is_anonymous' => 'boolean',
        'star_rating' => 'integer',
        'reply_updated_at' => 'datetime',
        'review_created_at' => 'datetime',
        'review_updated_at' => 'datetime',
    ];

    /** GBP starRating enum → 1-5 int. */
    public const STAR_MAP = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    public static function starToInt(?string $star): int
    {
        return self::STAR_MAP[$star] ?? 0;
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Reviews the owner has not yet answered.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<GbpReview>  $query
     * @return \Illuminate\Database\Eloquent\Builder<GbpReview>
     */
    public function scopeUnreplied($query)
    {
        return $query->whereNull('reply_comment');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<GbpReview>  $query
     * @return \Illuminate\Database\Eloquent\Builder<GbpReview>
     */
    public function scopeForRestaurant($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }
}
