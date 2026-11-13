<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Carbon\Carbon;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Agents\Models\CallLog;

/**
 * P6-09 V-20: a platform alert when a store's phone-agent spend spikes.
 *
 * V-12 voice cost rows are not merged yet, so billed call minutes (sum of ceil(duration_seconds / 60) per call)
 * stand in for the cost. For every store with calls today: an anomaly is today >= 3x its previous-7-full-day
 * average AND today >= 30 minutes (so a tiny store does not alert); a zero baseline (no calls the previous week)
 * alerts at 60 minutes instead. The cross-store query runs under {@see TenantContext::bypass()} and each anomaly
 * goes through {@see AlertDispatcher} (de-duped per day/kind/message).
 */
final class VoiceCostAnomaly
{
    /** Minutes floor before a store with history can alert. */
    private const MINUTES_FLOOR = 30;

    /** Minutes that alert a store with no usage in the previous 7 days. */
    private const ZERO_BASELINE_FLOOR = 60;

    /** @return list<array{store_id:int,today:int,avg:float}> */
    public function check(?Carbon $now = null): array
    {
        $tz = (string) config('app.timezone', 'UTC');
        $today = ($now ?? Carbon::now($tz))->copy()->setTimezone($tz);
        $todayDate = $today->toDateString();
        $windowStart = $today->copy()->startOfDay()->subDays(7);
        $windowEnd = $today->copy()->startOfDay()->addDay();

        /** @var array<int, array<string, int>> $minutesByStoreDay */
        $minutesByStoreDay = TenantContext::bypass(function () use ($windowStart, $windowEnd): array {
            $rows = CallLog::query()
                ->where('created_at', '>=', $windowStart)
                ->where('created_at', '<', $windowEnd)
                ->get(['store_id', 'duration_seconds', 'created_at']);

            $map = [];
            foreach ($rows as $row) {
                $day = $row->created_at?->toDateString();
                if ($day === null) {
                    continue;
                }
                $minutes = (int) ceil(max(0, (int) $row->duration_seconds) / 60);
                $storeId = (int) $row->store_id;
                $map[$storeId][$day] = ($map[$storeId][$day] ?? 0) + $minutes;
            }

            ksort($map);

            return $map;
        });

        $anomalies = [];
        foreach ($minutesByStoreDay as $storeId => $byDay) {
            if (! array_key_exists($todayDate, $byDay)) {
                continue; // no calls today
            }
            $todayMinutes = $byDay[$todayDate];

            $previousTotal = 0;
            for ($offset = 1; $offset <= 7; $offset++) {
                $previousTotal += $byDay[$today->copy()->subDays($offset)->toDateString()] ?? 0;
            }
            $avg = (float) $previousTotal / 7;

            $anomaly = $avg === 0.0
                ? $todayMinutes >= self::ZERO_BASELINE_FLOOR
                : $todayMinutes >= 3 * $avg && $todayMinutes >= self::MINUTES_FLOOR;
            if (! $anomaly) {
                continue;
            }

            AlertDispatcher::send(
                'voice_cost_anomaly',
                "Phone agent store {$storeId}: {$todayMinutes} min today vs ".round($avg, 1)." min/day average (7d)"
            );

            $anomalies[] = ['store_id' => $storeId, 'today' => $todayMinutes, 'avg' => $avg];
        }

        return $anomalies;
    }
}
