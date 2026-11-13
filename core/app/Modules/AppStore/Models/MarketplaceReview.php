<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\User;

/**
 * stores P1-3: a review on a Theme or App listing. Written by an eligible user
 * (verified purchase / active-or-past install — checked at write time, P3), one
 * per user per listing (editable), published ONLY after moderation. `body` is
 * hostile input: escape at render, never raw HTML. reviewable = Theme|App.
 */
class MarketplaceReview extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'rating' => 'integer',
        'rejection_count' => 'integer',
        'rejected_at' => 'datetime',
    ];

    public function reviewable()
    {
        return $this->morphTo();
    }

    /**
     * P6-350: cap rejections and require a 24h cooldown after each rejection.
     * Returns null when an edit is allowed, or a short translated reason key.
     */
    public function blockedByRejection(): ?string
    {
        if ($this->status !== 'rejected') {
            return null;
        }

        if (($this->rejection_count ?? 0) >= 3) {
            return 'you_have_reached_the_review_resubmission_limit_please_contact_support';
        }

        if ($this->rejected_at && $this->rejected_at->gt(now()->subDay())) {
            return 'please_wait_24_hours_after_a_rejection_before_resubmitting_your_review';
        }

        return null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Recompute the denormalized rating_avg/rating_count on a reviewable
     * (Theme|App) from its PUBLISHED reviews only. Called after moderation.
     */
    public static function recomputeAggregates(Model $reviewable): void
    {
        $published = static::query()
            ->where('reviewable_type', $reviewable->getMorphClass())
            ->where('reviewable_id', $reviewable->getKey())
            ->where('status', 'published');

        $reviewable->forceFill([
            'rating_count' => (int) $published->count(),
            'rating_avg' => round((float) $published->avg('rating'), 2),
        ])->save();
    }
}
