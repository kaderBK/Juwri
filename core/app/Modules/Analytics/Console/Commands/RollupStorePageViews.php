<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Console\Commands;

use App\Models\StoreView;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * P8 — roll up raw store_page_views into per-day device/lang aggregates (post_view_daily) and,
 * when retention is enabled, prune the raw rows that have been rolled up.
 *
 *  - Default: rolls up yesterday (the nightly cadence). --all backfills every raw day up
 *    to yesterday; --date=YYYY-MM-DD rolls up one day.
 *  - Idempotent: the upsert writes the recomputed count, so re-running yields the same rows.
 *  - Prune is gated by POST_VIEW_RETENTION_DAYS (config perf.post_view_retention, default 0
 *    = disabled) or the --days= override, and only deletes raw rows past the cutoff.
 */
class RollupStorePageViews extends Command
{
    protected $signature = 'postviews:rollup {--date=} {--all} {--prune} {--days=}';

    protected $description = 'Roll up raw store_page_views into store_page_view_daily and optionally prune old raw rows';

    public function handle(): int
    {
        $dates = $this->targetDates();

        $rows = 0;
        foreach ($dates as $date) {
            $rows += $this->rollupDate($date);
        }
        $this->info('Rolled up '.count($dates).' day(s), '.$rows.' aggregate row(s).');

        $days = $this->retentionDays();
        if ($days > 0) {
            $cutoff = now()->subDays($days)->startOfDay();
            // Only raw rows older than the cutoff — those days are already rolled up.
            $deleted = StoreView::where('date', '<', $cutoff)->delete();
            $this->info("Pruned {$deleted} raw store_page_views older than {$days} days.");
        } else {
            $this->info('Prune disabled (POST_VIEW_RETENTION_DAYS=0) — no raw rows deleted.');
        }

        return self::SUCCESS;
    }

    /** @return array<int,string> ISO dates to roll up */
    private function targetDates(): array
    {
        if ($this->option('date')) {
            return [(string) $this->option('date')];
        }

        if ($this->option('all')) {
            // Every distinct raw day up to yesterday (column expr, never a prefixed table name).
            return StoreView::where('date', '<', now()->startOfDay())
                ->selectRaw('DATE(date) as d')
                ->distinct()
                ->orderBy('d')
                ->pluck('d')
                ->map(fn ($d) => (string) $d)
                ->all();
        }

        return [now()->subDay()->toDateString()];
    }

    private function rollupDate(string $date): int
    {
        $agg = StoreView::whereRaw('DATE(date) = ?', [$date])
            ->selectRaw('store_id, device, lang, COUNT(*) as scans')
            ->groupBy('store_id', 'device', 'lang')
            ->toBase()
            ->get();

        if ($agg->isEmpty()) {
            return 0;
        }

        $payload = $agg->map(fn ($r) => [
            'store_id' => (int) $r->store_id,
            'date' => $date,
            'device' => $r->device,
            'lang' => $r->lang,
            'scans' => (int) $r->scans,
        ])->all();

        // Upsert on the unique key so a re-run overwrites (idempotent), not duplicates.
        DB::table('store_page_view_daily')->upsert($payload, ['store_id', 'date', 'device', 'lang'], ['scans']);

        return count($payload);
    }

    private function retentionDays(): int
    {
        if ($this->option('prune') && $this->option('days') !== null && $this->option('days') !== '') {
            return (int) $this->option('days');
        }
        if ($this->option('days') !== null && $this->option('days') !== '') {
            return (int) $this->option('days');
        }

        // P6: config('perf.post_view_retention') already captures POST_VIEW_RETENTION_DAYS
        // at config-build time, so the redundant env() fallback (null under config:cache) is dropped.
        return (int) config('perf.post_view_retention', 0);
    }
}
