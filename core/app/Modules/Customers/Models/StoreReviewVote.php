<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\StoreReview;

/**
 * DIRECTORY P0-2: a "helpful" vote.
 *
 * `voter_hash` is a hash of the session (plus the review id), never a raw
 * identifier — the unique index on (review_id, voter_hash) is what enforces
 * one vote per voter.
 */
class StoreReviewVote extends Model
{
    protected $guarded = [];

    public function review()
    {
        return $this->belongsTo(StoreReview::class, 'review_id');
    }
}
