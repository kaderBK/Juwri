<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Agents;

use Carbon\Carbon;
use Juwri\Modules\Agents\Models\AgentConversation;

/**
 * AO1 — agent conversation → order conversion analytics. Answers "how many chats
 * turned into orders, per channel?" so the AI-ordering feature's ROI is visible.
 * Scoped to one restaurant (post).
 */
class AgentAnalyticsService
{
    public static function conversionSummary(int $storeId, ?string $from = null, ?string $to = null): array
    {
        // Parse the range on the store's clock (not the platform tz) and never 500 on a
        // garbage ?from/?to — same pattern as SalesAnalyticsService / DashboardController.
        $appTz = config('app.timezone') ?: 'UTC';
        $tz = store_tz($storeId);
        $fromLocal = rescue(fn () => $from ? Carbon::parse($from, $tz)->startOfDay() : Carbon::now($tz)->subDays(30)->startOfDay(), Carbon::now($tz)->subDays(30)->startOfDay(), false);
        $toLocal = rescue(fn () => $to ? Carbon::parse($to, $tz)->endOfDay() : Carbon::now($tz)->endOfDay(), Carbon::now($tz)->endOfDay(), false);
        if ($toLocal->lt($fromLocal)) {
            [$fromLocal, $toLocal] = [$toLocal->copy()->startOfDay(), $fromLocal->copy()->endOfDay()];
        }
        $fromDate = $fromLocal->copy()->setTimezone($appTz);
        $toDate = $toLocal->copy()->setTimezone($appTz);

        $convos = AgentConversation::where('store_id', $storeId)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->get(['channel', 'order_id']);

        $total = $convos->count();
        $converted = $convos->whereNotNull('order_id')->count();

        $byChannel = $convos->groupBy('channel')->map(function ($rows) {
            $t = $rows->count();
            $c = $rows->whereNotNull('order_id')->count();

            return [
                'conversations' => $t,
                'orders' => $c,
                'conversion' => $t ? round($c / $t * 100, 1) : 0.0,
            ];
        });

        return [
            'range' => ['from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()],
            'conversations' => $total,
            'orders' => $converted,
            'conversion' => $total ? round($converted / $total * 100, 1) : 0.0,
            'by_channel' => $byChannel,
        ];
    }
}
