<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;


/**
 * GOOGLE-PARTNER Lane B — Google Business Profile ENGAGEMENT surface: reviews
 * (v4 accounts.locations.reviews), Local Posts, and the Performance API. This is
 * a SEPARATE interface from A's {@see GbpClientInterface} (place-action links) on
 * purpose: B owns its own contract + Fake + real client + factory so the two
 * lanes never edit each other's files. Both share the ONE GBP OAuth connection
 * (Credential feature='gbp', config services.gbp.driver) — A2's token-refresh
 * helper is the merge-time (F0) wiring point for the real client's Bearer token.
 *
 * Everything is DARK by default: the factory returns the Fake unless
 * services.gbp.driver === 'real' AND a stored access_token exists AND the real
 * class is present, so a creds-less environment makes zero HTTP calls.
 */
interface GbpEngagementClientInterface
{
    /**
     * List reviews for a location (v4). Returns the API-shaped page:
     * ['reviews'=>[ ['reviewId'=>..,'reviewer'=>['displayName'=>..,'isAnonymous'=>bool,'profilePhotoUrl'=>..],
     *   'starRating'=>'ONE|TWO|THREE|FOUR|FIVE','comment'=>..,'createTime'=>..,'updateTime'=>..,
     *   'reviewReply'=>['comment'=>..,'updateTime'=>..]|null ], ... ],
     *  'averageRating'=>float, 'totalReviewCount'=>int, 'nextPageToken'=>?string].
     *
     * @return array<string, mixed>
     */
    public function listReviews(string $locationId, ?string $pageToken = null): array;

    /**
     * Reply to (or update the reply on) a review. $reviewName is the full v4
     * resource name accounts/{a}/locations/{l}/reviews/{r}. $text ≤ 4096 bytes.
     * Returns the updated reviewReply ['comment'=>..,'updateTime'=>..].
     *
     * @return array<string, mixed>
     */
    public function replyReview(string $reviewName, string $text): array;

    /** Delete the owner reply on a review (v4 reviews.deleteReply). */
    public function deleteReviewReply(string $reviewName): void;

    /**
     * Create a Local Post for a location. $body is the API LocalPost shape
     * (languageCode, summary, callToAction{actionType,url}, media[], topicType…).
     * Returns the created post incl. server fields (name, state, createTime).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createLocalPost(string $locationId, array $body): array;

    /**
     * List recent Local Posts for a location. Returns
     * ['localPosts'=>[...], 'nextPageToken'=>?string].
     *
     * @return array<string, mixed>
     */
    public function listLocalPosts(string $locationId, ?string $pageToken = null): array;

    /**
     * Fetch a daily-metrics time series (Performance API v1
     * fetchMultiDailyMetricsTimeSeries). $metrics = ['WEBSITE_CLICKS', …];
     * $range = ['start'=>['year'=>..,'month'=>..,'day'=>..], 'end'=>[...]].
     * Returns ['metrics'=>[ 'METRIC_NAME'=>int total, … ], 'series'=>[ 'METRIC'=>[['date'=>'Y-m-d','value'=>int], …] ]].
     *
     * @param  array<int, string>  $metrics
     * @param  array<string, array<string, int>>  $range
     * @return array<string, mixed>
     */
    public function fetchPerformanceReport(string $locationId, array $metrics, array $range): array;
}
