<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\User;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Ai\Contracts\AiLimitExceeded;
use Juwri\Modules\Ai\Contracts\AiProviderFailure;
use Juwri\Modules\Ai\Data\CopyLanguage;
use Juwri\Modules\Integrations\Models\GbpReview;
use Juwri\Modules\Integrations\Services\Gbp\GbpEngagementClientFactory;
use Juwri\Modules\Integrations\Services\Gbp\GbpReviewReplyWriter;

/**
 * GOOGLE-PARTNER Lane B / B3: Google Business Profile reviews INBOX. Lists the
 * cached `gbp_reviews` (populated by PullGbpReviewsJob), lets the owner filter to
 * unreplied, and post a reply that goes to Google (via the engagement client) AND
 * echoes into the local row. Tenant-scoped (cross-tenant → 403); behind the
 * gbp_link feature gate at the route.
 *
 * These are Google reviews — kept strictly apart from platform-native
 * `store_reviews`; this controller never reads or writes that table.
 */
class GbpReviewsController extends Controller
{
    use ResolvesCurrentStore;

    /**
     * Typed override of the shared trait's untyped return (keeps PHPStan level 6
     * clean for this new controller without touching the shared trait).
     *
     * @return array<int, int>
     */
    protected function storeIds(): array
    {
        return dashboard_store_ids();
    }

    public function index(Request $request): View
    {
        $store = $this->currentRestaurant($request);
        $creds = Credential::for('gbp', $store->id) ?? [];
        $connected = ! empty($creds['location_id']);

        $unrepliedOnly = $request->boolean('unreplied');
        $query = GbpReview::forRestaurant($store->id)
            ->orderByDesc('review_created_at')
            ->orderByDesc('id');
        if ($unrepliedOnly) {
            $query->unreplied();
        }
        $reviews = $query->limit(200)->get();

        return view('owner.gbp.reviews', [
            'rid' => $store->id,
            'connected' => $connected,
            'reviews' => $reviews,
            'unrepliedOnly' => $unrepliedOnly,
            'unrepliedCount' => GbpReview::forRestaurant($store->id)->unreplied()->count(),
        ]);
    }

    public function reply(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate([
            'review_id' => ['required', 'integer'],
            // GBP caps a reply at 4096 BYTES.
            'text' => ['required', 'string', 'max:4096'],
        ]);

        // Cross-tenant guard: the review must belong to THIS restaurant.
        $review = GbpReview::forRestaurant($store->id)->findOrFail((int) $data['review_id']);

        $creds = Credential::for('gbp', $store->id) ?? [];
        $account = $creds['account_id'] ?? '';
        $reviewName = ($account ? "accounts/{$account}/" : '')
            ."locations/{$review->location_id}/reviews/{$review->external_id}";

        $reply = GbpEngagementClientFactory::make($store->id)->replyReview($reviewName, $data['text']);

        // Local echo so the inbox reflects the reply immediately.
        $review->reply_comment = $reply['comment'] ?? $data['text'];
        $review->reply_updated_at = now();
        $review->save();

        return back()->with('success', ___('Reply posted to Google.'));
    }

    /**
     * P1-17 (J27-B): an AI draft of the reply to one review (JSON). Never posts: the owner edits the text in the reply
     * box and sends it with reply(). Same store scoping as reply(); `ratelimit:ai` on the route; AI credits and spend
     * caps inside AiService. Spam/abusive reviews are refused before any AI call.
     */
    public function draftReply(Request $request, GbpReviewReplyWriter $writer): JsonResponse
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate([
            'review_id' => ['required', 'integer'],
            'lang' => ['nullable', 'string', 'in:'.implode(',', CopyLanguage::CODES)],
        ]);

        // Cross-tenant guard: the review must belong to THIS restaurant.
        $review = GbpReview::forRestaurant($store->id)->findOrFail((int) $data['review_id']);

        if (GbpReviewReplyWriter::refuses($review)) {
            return response()->json(['error' => ___('This review looks like spam or abuse, so no AI draft was made. Write your reply yourself or report the review to Google.')], 422);
        }

        $language = CopyLanguage::pick($data['lang'] ?? null, (int) $store->id);

        try {
            $text = $writer->draft($review, (string) $store->title, $language, (int) $request->user()->id, (int) $store->id);
        } catch (AiLimitExceeded $e) {
            return response()->json(['error' => $e->getMessage()], 429);
        } catch (AiProviderFailure $e) {
            Log::warning('[AI review reply] '.$e->getMessage());

            return response()->json(['error' => ___('The AI service is unavailable right now. Please try again in a moment.')], 502);
        }

        return response()->json([
            'success' => true,
            'reply' => $text,
            'lang' => $language->code,
            'dir' => $language->dir(),
        ]);
    }

    public function deleteReply(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate(['review_id' => ['required', 'integer']]);

        $review = GbpReview::forRestaurant($store->id)->findOrFail((int) $data['review_id']);

        $creds = Credential::for('gbp', $store->id) ?? [];
        $account = $creds['account_id'] ?? '';
        $reviewName = ($account ? "accounts/{$account}/" : '')
            ."locations/{$review->location_id}/reviews/{$review->external_id}";

        GbpEngagementClientFactory::make($store->id)->deleteReviewReply($reviewName);

        $review->reply_comment = null;
        $review->reply_updated_at = null;
        $review->save();

        return back()->with('success', ___('Reply removed from Google.'));
    }
}
