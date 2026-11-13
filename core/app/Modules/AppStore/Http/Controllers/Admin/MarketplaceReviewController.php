<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\AppStore\Models\MarketplaceReview;

/**
 * stores P3-2: moderation queue for store reviews (both themes and apps). A
 * review is written `pending` (P3-1); an admin publishes or rejects it here, and
 * the denormalized rating_avg/rating_count on the listing is recomputed from the
 * PUBLISHED set on every decision.
 */
class MarketplaceReviewController extends Controller
{
    public function index()
    {
        $pending = MarketplaceReview::with(['reviewable', 'user'])
            ->where('status', 'pending')->orderBy('created_at')->get();
        $recent = MarketplaceReview::with(['reviewable', 'user'])
            ->whereIn('status', ['published', 'rejected'])
            ->orderByDesc('updated_at')->limit(20)->get();

        return view('admin.marketplace_reviews.index', compact('pending', 'recent'));
    }

    public function approve(int $id)
    {
        $this->decide($id, 'published');
        app_alert_success(___('Review published.'));

        return redirect()->route('admin.reviews.index');
    }

    public function reject(int $id)
    {
        $this->decide($id, 'rejected');
        app_alert_success(___('Review rejected.'));

        return redirect()->route('admin.reviews.index');
    }

    private function decide(int $id, string $status): void
    {
        $review = MarketplaceReview::findOrFail($id);
        // P6-350: only a `rejected` decision stamps rejected_at and bumps
        // rejection_count (so the author cannot resubmit without limit);
        // a `published` decision leaves them alone.
        $changes = ['status' => $status];
        if ($status === 'rejected') {
            $changes['rejected_at'] = now();
            $changes['rejection_count'] = ($review->rejection_count ?? 0) + 1;
        }
        $review->update($changes);

        if ($reviewable = $review->reviewable) {
            MarketplaceReview::recomputeAggregates($reviewable);
        }
    }
}
