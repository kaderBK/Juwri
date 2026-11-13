<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;

use Illuminate\Support\Facades\Http;

/**
 * Real GBP engagement client (reviews v4 + Local Posts + Performance v1). DARK:
 * only instantiated by {@see GbpEngagementClientFactory} when services.gbp.driver
 * === 'real' AND a stored access_token exists. Never touched by tests (Fake is the
 * default). No live call is made anywhere in this pack — F0 wires real creds.
 *
 * F0 WIRING TO-DO (handshake B→A2): the Bearer token below is read straight from
 * the stored Credential. Lane A's A2 delivers the single-choke-point token-REFRESH
 * helper; at merge, route {@see token()} through that helper so an expired
 * access_token is refreshed before each call. Until then this uses the raw stored
 * token (correct while dark; would 401 on an expired token if flipped live early).
 */
class GbpEngagementClient implements GbpEngagementClientInterface
{
    private const V4_BASE = 'https://mybusiness.googleapis.com/v4';

    private const PERF_BASE = 'https://businessprofileperformance.googleapis.com/v1';

    /** @param array<string,mixed> $creds */
    public function __construct(private array $creds, private ?int $storeId = null) {}

    /** The restaurant this client is scoped to (F0: the refresh helper keys on it). */
    public function storeId(): ?int
    {
        return $this->storeId;
    }

    private function token(): string
    {
        // F0 WIRING (handshake B→A2): use Lane A's refresh choke point so a live,
        // auto-refreshed bearer is always sent. Falls back to the raw stored token
        // when no restaurant is scoped (never in production; keeps unit fakes simple).
        $rid = $this->storeId();
        if ($rid !== null) {
            $fresh = GbpTokenService::accessTokenFor($rid);
            if ($fresh !== null && $fresh !== '') {
                return $fresh;
            }
        }

        return (string) ($this->creds['access_token'] ?? '');
    }

    /** accounts/{acc}/locations/{loc} — account from creds (set by A4 location picker). */
    private function parent(string $locationId): string
    {
        $account = (string) ($this->creds['account_id'] ?? '');
        if (str_contains($locationId, 'locations/')) {
            return $account ? "accounts/{$account}/{$locationId}" : $locationId;
        }

        return $account
            ? "accounts/{$account}/locations/{$locationId}"
            : "locations/{$locationId}";
    }

    /** @return array<string, mixed> */
    public function listReviews(string $locationId, ?string $pageToken = null): array
    {
        $resp = Http::withToken($this->token())
            ->get(self::V4_BASE.'/'.$this->parent($locationId).'/reviews', array_filter([
                'pageSize' => 50,
                'pageToken' => $pageToken,
            ]))->throw()->json();

        return [
            'reviews' => $resp['reviews'] ?? [],
            'averageRating' => (float) ($resp['averageRating'] ?? 0),
            'totalReviewCount' => (int) ($resp['totalReviewCount'] ?? 0),
            'nextPageToken' => $resp['nextPageToken'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function replyReview(string $reviewName, string $text): array
    {
        return Http::withToken($this->token())
            ->put(self::V4_BASE.'/'.$reviewName.'/reply', ['comment' => $text])
            ->throw()->json() ?: ['comment' => $text];
    }

    public function deleteReviewReply(string $reviewName): void
    {
        Http::withToken($this->token())
            ->delete(self::V4_BASE.'/'.$reviewName.'/reply')
            ->throw();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createLocalPost(string $locationId, array $body): array
    {
        return Http::withToken($this->token())
            ->post(self::V4_BASE.'/'.$this->parent($locationId).'/localPosts', $body)
            ->throw()->json() ?: $body;
    }

    /** @return array<string, mixed> */
    public function listLocalPosts(string $locationId, ?string $pageToken = null): array
    {
        $resp = Http::withToken($this->token())
            ->get(self::V4_BASE.'/'.$this->parent($locationId).'/localPosts', array_filter([
                'pageSize' => 20,
                'pageToken' => $pageToken,
            ]))->throw()->json();

        return [
            'localPosts' => $resp['localPosts'] ?? [],
            'nextPageToken' => $resp['nextPageToken'] ?? null,
        ];
    }

    /**
     * @param  array<int, string>  $metrics
     * @param  array<string, array<string, int>>  $range
     * @return array<string, mixed>
     */
    public function fetchPerformanceReport(string $locationId, array $metrics, array $range): array
    {
        $loc = str_contains($locationId, 'locations/') ? $locationId : "locations/{$locationId}";
        $query = ['dailyMetrics' => $metrics];
        foreach (['start' => 'start_date', 'end' => 'end_date'] as $k => $param) {
            foreach (['year', 'month', 'day'] as $part) {
                $query["dailyRange.{$param}.{$part}"] = $range[$k][$part] ?? null;
            }
        }

        $resp = Http::withToken($this->token())
            ->get(self::PERF_BASE.'/'.$loc.':fetchMultiDailyMetricsTimeSeries', array_filter($query, fn ($v) => $v !== null))
            ->throw()->json();

        return $this->normalizePerformance($resp, $metrics);
    }

    /**
     * Flatten the nested multiDailyMetricTimeSeries into {metrics:totals, series:points}.
     *
     * @param  array<string, mixed>  $resp
     * @param  array<int, string>  $requested
     * @return array<string, mixed>
     */
    private function normalizePerformance(array $resp, array $requested): array
    {
        $out = ['metrics' => array_fill_keys($requested, 0), 'series' => array_fill_keys($requested, [])];
        foreach ($resp['multiDailyMetricTimeSeries'] ?? [] as $container) {
            foreach ($container['dailyMetricTimeSeries'] ?? [] as $tuple) {
                $metric = $tuple['dailyMetric'] ?? null;
                if ($metric === null) {
                    continue;
                }
                $total = 0;
                $series = [];
                foreach ($tuple['timeSeries']['datedValues'] ?? [] as $dv) {
                    $d = $dv['date'] ?? [];
                    $value = (int) ($dv['value'] ?? 0);
                    $series[] = [
                        'date' => sprintf('%04d-%02d-%02d', $d['year'] ?? 0, $d['month'] ?? 0, $d['day'] ?? 0),
                        'value' => $value,
                    ];
                    $total += $value;
                }
                $out['series'][$metric] = $series;
                $out['metrics'][$metric] = $total;
            }
        }

        return $out;
    }
}
