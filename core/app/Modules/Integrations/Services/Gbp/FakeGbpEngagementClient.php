<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;

/**
 * In-memory Fake for the GBP engagement surface. Never hits Google; deterministic
 * for tests + the fake-driver Chrome walk. Static stores so a test can pre-seed a
 * location's reviews/posts and assert reply/create mutations. reset() between tests.
 */
class FakeGbpEngagementClient implements GbpEngagementClientInterface
{
    /** @var array<string, array<int, array<string, mixed>>> reviews by locationId */
    public static array $reviews = [];

    /** @var array<string, array<int, array<string, mixed>>> local posts by locationId */
    public static array $gbpPosts = [];

    private static int $seq = 0;

    public static function reset(): void
    {
        self::$reviews = [];
        self::$gbpPosts = [];
        self::$seq = 0;
    }

    /**
     * Seed helper for tests/walks.
     *
     * @param  array<string, mixed>  $review
     */
    public static function seedReview(string $locationId, array $review): void
    {
        self::$reviews[$locationId][] = $review;
    }

    /** @return array<string, mixed> */
    public function listReviews(string $locationId, ?string $pageToken = null): array
    {
        $reviews = self::$reviews[$locationId] ?? [];
        $sum = 0;
        $rated = 0;
        foreach ($reviews as $r) {
            $n = self::starToInt($r['starRating'] ?? null);
            if ($n > 0) {
                $sum += $n;
                $rated++;
            }
        }

        return [
            'reviews' => array_values($reviews),
            'averageRating' => $rated ? round($sum / $rated, 1) : 0.0,
            'totalReviewCount' => count($reviews),
            'nextPageToken' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function replyReview(string $reviewName, string $text): array
    {
        $reply = ['comment' => $text, 'updateTime' => '2026-01-01T00:00:00Z'];
        foreach (self::$reviews as &$byLoc) {
            foreach ($byLoc as &$review) {
                if (($review['name'] ?? null) === $reviewName
                    || ($review['reviewId'] ?? null) === self::lastSegment($reviewName)) {
                    $review['reviewReply'] = $reply;

                    return $reply;
                }
            }
        }

        return $reply;
    }

    public function deleteReviewReply(string $reviewName): void
    {
        foreach (self::$reviews as &$byLoc) {
            foreach ($byLoc as &$review) {
                if (($review['name'] ?? null) === $reviewName
                    || ($review['reviewId'] ?? null) === self::lastSegment($reviewName)) {
                    $review['reviewReply'] = null;

                    return;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createLocalPost(string $locationId, array $body): array
    {
        self::$seq++;
        $store = array_merge([
            'name' => "locations/{$locationId}/localPosts/".self::$seq,
            'state' => 'LIVE',
            'createTime' => '2026-01-01T00:00:00Z',
            'updateTime' => '2026-01-01T00:00:00Z',
            'searchUrl' => 'https://maps.google.com/localpost/'.self::$seq,
        ], $body);
        self::$gbpPosts[$locationId][] = $store;

        return $store;
    }

    /** @return array<string, mixed> */
    public function listLocalPosts(string $locationId, ?string $pageToken = null): array
    {
        return [
            'localPosts' => array_values(self::$gbpPosts[$locationId] ?? []),
            'nextPageToken' => null,
        ];
    }

    /**
     * @param  array<int, string>  $metrics
     * @param  array<string, array<string, int>>  $range
     * @return array<string, mixed>
     */
    public function fetchPerformanceReport(string $locationId, array $metrics, array $range): array
    {
        // Deterministic per-metric daily value = (metric length % 9) + 1, over the range.
        $days = self::daysBetween($range['start'] ?? [], $range['end'] ?? []);
        $out = ['metrics' => [], 'series' => []];
        foreach ($metrics as $metric) {
            $daily = (strlen((string) $metric) % 9) + 1;
            $series = [];
            $total = 0;
            foreach ($days as $d) {
                $series[] = ['date' => $d, 'value' => $daily];
                $total += $daily;
            }
            $out['series'][$metric] = $series;
            $out['metrics'][$metric] = $total;
        }

        return $out;
    }

    private static function starToInt(?string $star): int
    {
        return ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5][$star] ?? 0;
    }

    private static function lastSegment(string $name): string
    {
        $parts = explode('/', $name);

        return end($parts) ?: $name;
    }

    /**
     * @param  array<string, int>  $start
     * @param  array<string, int>  $end
     * @return array<int, string> Y-m-d dates inclusive (capped at 45 for safety).
     */
    private static function daysBetween(array $start, array $end): array
    {
        if (empty($start) || empty($end)) {
            return ['2026-01-01'];
        }
        $s = mktime(0, 0, 0, (int) ($start['month'] ?? 1), (int) ($start['day'] ?? 1), (int) ($start['year'] ?? 2026));
        $e = mktime(0, 0, 0, (int) ($end['month'] ?? 1), (int) ($end['day'] ?? 1), (int) ($end['year'] ?? 2026));
        $out = [];
        for ($t = $s, $i = 0; $t <= $e && $i < 45; $t += 86400, $i++) {
            $out[] = date('Y-m-d', $t);
        }

        return $out ?: ['2026-01-01'];
    }
}
