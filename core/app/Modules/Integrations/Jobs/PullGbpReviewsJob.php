<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Jobs;

use App\Services\FeatureAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Integrations\Models\GbpReview;
use Juwri\Modules\Integrations\Services\Gbp\GbpEngagementClientFactory;

/**
 * GOOGLE-PARTNER Lane B / B2: pull one restaurant's Google Business Profile reviews
 * into the local `gbp_reviews` cache.
 *
 * DARK + idempotent: no-op unless the store has a connected GBP location AND the
 * gbp_link entitlement holds. Reviews are upserted on the natural key
 * [location_id, external_id], so a re-pull refreshes rows without duplicating. The
 * factory returns the Fake unless real GBP creds are wired (F0), so a creds-less
 * environment makes zero HTTP calls. Fail-soft: a Google-side error is logged and
 * swallowed so the nightly sweep of other stores is unaffected.
 *
 * F0 WIRING (handshake B→A2): the real client's Bearer token is read raw today;
 * A2's token-refresh helper must be routed into GbpEngagementClient::token() at
 * merge before this job is flipped live.
 */
class PullGbpReviewsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $storeId) {}

    public function handle(): void
    {
        $creds = Credential::for('gbp', $this->storeId);
        $locationId = $creds['location_id'] ?? null;
        if (empty($locationId)) {
            return; // not connected → zero behavior
        }
        if (! FeatureAccess::storeAllows($this->storeId, 'gbp_link')) {
            return; // kill-switch / entitlement veto
        }

        $client = GbpEngagementClientFactory::make($this->storeId);
        $pageToken = null;

        try {
            do {
                $page = $client->listReviews((string) $locationId, $pageToken);
                foreach ($page['reviews'] ?? [] as $review) {
                    $this->upsert((string) $locationId, $review);
                }
                $pageToken = $page['nextPageToken'] ?? null;
            } while (! empty($pageToken));
        } catch (\Throwable $e) {
            // Fail-soft: never let one store's outage break the nightly sweep.
            Log::warning('gbp.reviews.pull_failed', [
                'store_id' => $this->storeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $review */
    private function upsert(string $locationId, array $review): void
    {
        $externalId = $review['reviewId'] ?? null;
        if (empty($externalId)) {
            return;
        }

        GbpReview::updateOrCreate(
            ['location_id' => $locationId, 'external_id' => (string) $externalId],
            [
                'store_id' => $this->storeId,
                'reviewer_name' => $review['reviewer']['displayName'] ?? null,
                'reviewer_photo_url' => $review['reviewer']['profilePhotoUrl'] ?? null,
                'reviewer_is_anonymous' => (bool) ($review['reviewer']['isAnonymous'] ?? false),
                'star_rating' => GbpReview::starToInt($review['starRating'] ?? null),
                'comment' => $review['comment'] ?? null,
                'reply_comment' => $review['reviewReply']['comment'] ?? null,
                'reply_updated_at' => $this->parseTime($review['reviewReply']['updateTime'] ?? null),
                'review_created_at' => $this->parseTime($review['createTime'] ?? null),
                'review_updated_at' => $this->parseTime($review['updateTime'] ?? null),
            ]
        );
    }

    private function parseTime(?string $rfc3339): ?string
    {
        if (empty($rfc3339)) {
            return null;
        }
        try {
            return Carbon::parse($rfc3339)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
