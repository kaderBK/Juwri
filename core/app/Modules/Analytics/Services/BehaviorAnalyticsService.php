<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Services;

use App\Models\StoreView;
use Carbon\Carbon;

/**
 * Analytics SA2 — anonymous scan-behavior aggregates: which languages diners scan in,
 * average time-on-menu, and where they drop off. GDPR-friendly (no PII, aggregate only).
 */
class BehaviorAnalyticsService
{
    public static function summary(array $storeIds, ?string $from = null, ?string $to = null): array
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->copy()->subDays(30);
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now();
        $q = StoreView::whereIn('store_id', $storeIds)->whereBetween('date', [$fromDate, $toDate]);
        $langs = (clone $q)->whereNotNull('lang')->selectRaw('lang, COUNT(*) as c')->groupBy('lang')->pluck('c', 'lang');
        $exits = (clone $q)->whereNotNull('exit_section')->selectRaw('exit_section, COUNT(*) as c')->groupBy('exit_section')->orderByDesc('c')->limit(10)->pluck('c', 'exit_section');
        $avgDwell = (clone $q)->whereNotNull('dwell_ms')->avg('dwell_ms');

        return [
            'language_split' => $langs,
            'avg_time_on_menu_sec' => $avgDwell ? round($avgDwell / 1000, 1) : null,
            'exit_sections' => $exits,
        ];
    }

    public static function heatmap(array $storeIds, ?string $from = null, ?string $to = null): array
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->copy()->subDays(30);
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now();
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        StoreView::whereIn('store_id', $storeIds)->whereBetween('date', [$fromDate, $toDate])->get(['date'])->each(function ($v) use (&$grid) {
            $c = Carbon::parse($v->date);
            $grid[(int) $c->dayOfWeek][(int) $c->hour]++;
        });

        return $grid;
    }

    public static function sourceSplit(array $storeIds, ?string $from = null, ?string $to = null): array
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->copy()->subDays(30);
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now();

        return StoreView::whereIn('store_id', $storeIds)->whereBetween('date', [$fromDate, $toDate])->whereNotNull('source')->selectRaw('source, COUNT(*) as c')->groupBy('source')->pluck('c', 'source')->toArray();
    }
}
