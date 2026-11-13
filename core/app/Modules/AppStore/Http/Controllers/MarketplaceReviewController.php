<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Controllers;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Theme;
use App\Services\ThemePurchaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\AppStore\Models\MarketplaceReview;

/**
 * stores P3-1: review WRITE for both stores. Eligibility is server-checked at
 * write time — a verified theme purchase (ThemePurchaseService::entitled) or an
 * app installation (active OR past). One review per user per listing (editable);
 * every write/edit lands as `pending` and is only shown after moderation (P3-2).
 */
class MarketplaceReviewController extends Controller
{
    /** Eligible = the current user has a verified purchase of this theme. */
    public static function themeEligible(Theme $theme): bool
    {
        return auth()->check() && ThemePurchaseService::entitled(auth()->user(), $theme);
    }

    /** Eligible = the current user has installed this app (active or past). */
    public static function appEligible(App $app): bool
    {
        return auth()->check() && AppInstallation::query()
            ->where('app_id', $app->id)->where('user_id', auth()->id())->exists();
    }

    public function storeTheme(Request $request, string $slug)
    {
        $theme = Theme::where('status', 'published')->where('slug', $slug)->firstOrFail();
        abort_unless(auth()->check(), 403);
        abort_unless(self::themeEligible($theme), 403, 'A verified purchase is required to review this theme.');

        return $this->upsert($request, $theme);
    }

    public function storeApp(Request $request, string $slug)
    {
        $app = App::where('status', 'approved')->where('slug', $slug)->firstOrFail();
        abort_unless(auth()->check(), 403);
        abort_unless(self::appEligible($app), 403, 'You must install this app before reviewing it.');

        return $this->upsert($request, $app);
    }

    /** One review per (listing,user): create or edit, always (re)set to pending. */
    private function upsert(Request $request, Model $reviewable)
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:'.config('marketplace.review.min_rating', 1), 'max:'.config('marketplace.review.max_rating', 5)],
            'title' => ['nullable', 'string', 'max:'.config('marketplace.review.title_max', 120)],
            'body' => ['nullable', 'string', 'max:'.config('marketplace.review.body_max', 2000)],
        ]);

        // Look up the existing review first so we can enforce the P6-350
        // rejection cooldown and cap, then recompute aggregates after write.
        $reviews = $reviewable->reviews();
        $existing = (clone $reviews)->where('user_id', auth()->id())->first();

        // P6-350: a rejected review cannot be resubmitted without limit —
        // refuse here without writing. A pending/published review may always be edited.
        if ($existing && ($reason = $existing->blockedByRejection())) {
            return redirect()->back()->withErrors(['review' => ___($reason)]);
        }

        $review = $reviews->updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'rating' => $data['rating'],
                'title' => $data['title'] ?? null,
                'body' => $data['body'] ?? null,
                'status' => 'pending',
            ]
        );

        // P6-350: an edited previously-published review must drop out of the
        // aggregate (it is `pending` now, not `published`) immediately, so the
        // detail page cannot show a stale rating while the new copy waits for
        // moderation.
        MarketplaceReview::recomputeAggregates($reviewable);

        return redirect()->back()
            ->with('review_status', 'pending')
            ->with('review_edited', $existing !== null);
    }
}
