<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Services;

use App\Services\FeatureAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Models\BioClick;

/**
 * bio-t15 Lane D — advanced bio insights (Tier 5). This service is the SOLE owner
 * of the analytics BREAKDOWNS that go beyond the LOCKED statsFor() shape: country
 * and referrer buckets, richer device/source detail, and the CSV export feed.
 *
 * F0 pre-wired BioLinkController::insights() to call extra(); the analytics lane
 * fills this in WITHOUT touching that controller or BioLinkService::statsFor().
 * Everything here reads bio_clicks directly and stays PII-free (coarse country +
 * host-only referrer — never IP, never full URL). See SPEC §7.
 *
 * Gating (SPEC §8) lives HERE, not in the controller or the view: country and
 * referrer breakdowns are `bio_analytics_pro` (PRO). extra() always returns the
 * `analytics_pro` flag (so the view can render an upsell nudge) but only fills
 * `countries`/`referrers` when the store is entitled — the UI badge is cosmetic,
 * this check is the real, server-side gate.
 */
class BioInsightsService
{
    /**
     * Advanced breakdowns merged into the insights view under `$extra`.
     *
     * @return array{analytics_pro:bool, geo_available:bool, countries:array<int,array{country:string,count:int}>, referrers:array<int,array{ref:string,count:int}>}
     */
    public function extra(int $storeId, string $range): array
    {
        $entitled = FeatureAccess::storeAllows($storeId, 'bio_analytics_pro');

        $result = [
            'analytics_pro' => $entitled,
            // D1: whether the platform's own geo seam (visitor_country(), a
            // trusted-proxy CF-IPCountry header or a local GeoLite2 mmdb) is
            // provisioned on THIS install. Both are dark by default, so a
            // fresh install correctly reports false here even once entitled —
            // the view uses this to tell "no country data yet" apart from
            // "you're not on the plan for this".
            'geo_available' => (bool) config('geoip.trust_cf_country') || trim((string) config('geoip.mmdb_path')) !== '',
            'countries' => [],
            'referrers' => [],
        ];

        if (! $entitled) {
            return $result;
        }

        $base = $this->baseQuery($storeId, $range)->where('block_id', BioClick::VIEW_SENTINEL);

        $result['countries'] = (clone $base)
            ->select('country', DB::raw('SUM(count) as c'))
            ->groupBy('country')->orderByDesc('c')->get()
            ->map(fn ($r) => ['country' => (string) $r->country, 'count' => (int) $r->getAttribute('c')])
            ->all();

        $result['referrers'] = (clone $base)
            ->where('ref', '!=', BioClick::REF_NONE)
            ->select('ref', DB::raw('SUM(count) as c'))
            ->groupBy('ref')->orderByDesc('c')->get()
            ->map(fn ($r) => ['ref' => (string) $r->ref, 'count' => (int) $r->getAttribute('c')])
            ->all();

        return $result;
    }

    /**
     * D4: the CSV export feed — one row per stored (block, day, source,
     * device, country, ref) grain cell, not re-aggregated further, so the
     * export is a faithful raw dump an owner can pivot in a spreadsheet. No
     * PII: day, block label, source, device, country, ref, count only.
     * `cursor()` keeps this O(1) memory regardless of range/volume — the
     * controller streams it straight to the HTTP response.
     *
     * @return LazyCollection<int, array{day:string, block_label:string, source:string, device:string, country:string, ref:string, count:int}>
     */
    public function csvRows(int $storeId, string $range): LazyCollection
    {
        $labels = BioBlock::query()->where('store_id', $storeId)->pluck('label', 'id');

        return $this->baseQuery($storeId, $range)
            ->orderBy('day')->orderBy('block_id')
            ->cursor()
            ->map(function (BioClick $row) use ($labels) {
                $isView = (int) $row->block_id === BioClick::VIEW_SENTINEL;

                return [
                    'day' => $row->day->toDateString(),
                    'block_label' => $isView ? '(page view)' : trim((string) ($labels[$row->block_id] ?? '')),
                    'source' => (string) $row->source,
                    'device' => (string) $row->device,
                    'country' => (string) $row->country,
                    'ref' => (string) $row->ref,
                    'count' => (int) $row->count,
                ];
            });
    }

    /**
     * store_id + range-windowed bio_clicks query, shared by extra() and csvRows().
     *
     * @return \Illuminate\Database\Eloquent\Builder<BioClick>
     */
    private function baseQuery(int $storeId, string $range): Builder
    {
        $since = $this->rangeStart($range);

        return BioClick::query()->where('store_id', $storeId)
            ->when($since !== null, fn ($q) => $q->where('day', '>=', $since->toDateString()));
    }

    /**
     * Duplicated (not reused) from BioLinkService::rangeStart() on purpose:
     * that method is `protected` on a Lane A/B-owned file Lane D does not
     * edit. Same four-value vocabulary BioLinkController validates `?range=`
     * against before calling extra(), so this always matches.
     */
    private function rangeStart(string $range): ?Carbon
    {
        return match ($range) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => null,
        };
    }
}
