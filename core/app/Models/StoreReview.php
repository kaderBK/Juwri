<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Modules\Customers\Models\StoreReviewMedia;
use Juwri\Modules\Customers\Models\StoreReviewVote;
use Juwri\Modules\Media\Models\MediaAsset;

/**
 * DIRECTORY P0-2: a review OF A PLACE (see the migration for why this is not
 * MenuReview).
 *
 * Never rendered on an unclaimed listing (D3) — a business that never opted in
 * does not get a public review wall on our say-so.
 */
class StoreReview extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'stars' => 'integer',
        'criteria' => 'array',
        'is_verified' => 'boolean',
        'helpful_count' => 'integer',
        'owner_replied_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** Photo join rows, ordered. */
    public function media()
    {
        return $this->hasMany(StoreReviewMedia::class, 'review_id')->orderBy('position');
    }

    /** The MediaAssets attached to this review, via the join table. */
    public function photos()
    {
        return $this->belongsToMany(MediaAsset::class, 'store_review_media', 'review_id', 'media_asset_id')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function votes()
    {
        return $this->hasMany(StoreReviewVote::class, 'review_id');
    }

    /** The public list never shows pending/rejected. */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    /** Order-linked reviews only — the trust signal. */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /** Most-helpful first, then newest — the default public ordering. */
    public function scopeRanked($query)
    {
        return $query->orderByDesc('helpful_count')->orderByDesc('created_at');
    }

    public function hasOwnerReply(): bool
    {
        return filled($this->owner_reply);
    }

    /**
     * DIRECTORY T2-2: superadmin setting — reviews publish immediately instead
     * of waiting in the moderation queue. Default OFF (D3): moderation is
     * pending-by-default and auto-approve is an opt-in the operator must flip.
     */
    public static function autoApproveEnabled(): bool
    {
        $value = config('settings.dir_reviews_auto_approve');

        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }
}
