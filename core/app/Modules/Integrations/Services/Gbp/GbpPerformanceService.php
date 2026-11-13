<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;

use App\Services\FeatureAccess;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\Credential;

/**
 * GOOGLE-PARTNER Lane B / B5: the GBP Performance widget's data source. Pulls a
 * 30-day daily-metrics time series via the engagement client and rolls it up to
 * the four headline numbers (views, calls, directions, website clicks). Cached
 * daily per store so the widget never hammers the Performance API on every render.
 *
 * DARK + graceful: a store that is not GBP-connected (or lacks the gbp_link
 * entitlement) returns available=false and zeros — the widget shows an empty
 * state and no HTTP call is made.
 */
class GbpPerformanceService
{
    private const CALLS = 'CALL_CLICKS';

    private const DIRECTIONS = 'BUSINESS_DIRECTION_REQUESTS';

    private const WEBSITE = 'WEBSITE_CLICKS';

    private const IMPRESSIONS = [
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
    ];

    /**
     * @return array{available:bool, views:int, calls:int, directions:int, website_clicks:int, range_days:int}
     */
    public function monthlySummary(int $storeId): array
    {
        $empty = ['available' => false, 'views' => 0, 'calls' => 0, 'directions' => 0, 'website_clicks' => 0, 'range_days' => 30];

        $creds = Credential::for('gbp', $storeId);
        $locationId = $creds['location_id'] ?? null;
        if (empty($locationId) || ! FeatureAccess::storeAllows($storeId, 'gbp_link')) {
            return $empty;
        }

        return Cache::remember("gbp_perf:{$storeId}", now()->addHours(24), function () use ($storeId, $locationId, $empty) {
            try {
                $metrics = array_merge([self::CALLS, self::DIRECTIONS, self::WEBSITE], self::IMPRESSIONS);
                $end = now();
                $start = now()->subDays(30);
                $report = GbpEngagementClientFactory::make($storeId)->fetchPerformanceReport(
                    (string) $locationId,
                    $metrics,
                    [
                        'start' => ['year' => (int) $start->year, 'month' => (int) $start->month, 'day' => (int) $start->day],
                        'end' => ['year' => (int) $end->year, 'month' => (int) $end->month, 'day' => (int) $end->day],
                    ]
                );
                $totals = $report['metrics'] ?? [];

                $views = 0;
                foreach (self::IMPRESSIONS as $m) {
                    $views += (int) ($totals[$m] ?? 0);
                }

                return [
                    'available' => true,
                    'views' => $views,
                    'calls' => (int) ($totals[self::CALLS] ?? 0),
                    'directions' => (int) ($totals[self::DIRECTIONS] ?? 0),
                    'website_clicks' => (int) ($totals[self::WEBSITE] ?? 0),
                    'range_days' => 30,
                ];
            } catch (\Throwable) {
                // Fail-soft: a Performance API blip renders the empty state, never a 500.
                return $empty;
            }
        });
    }
}
