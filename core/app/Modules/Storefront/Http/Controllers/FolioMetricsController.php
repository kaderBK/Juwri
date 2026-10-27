<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Storefront\Models\FolioPageStat;

/**
 * FP B8 — public, anonymous flip-book page-view beacon.
 *
 * The storefront's navigator.sendBeacon() posts `{page, ms}` here on every
 * settled page change. It is a public route (no feature/entitled middleware —
 * a diner is not a subject) and CSRF-exempt (sendBeacon carries no token). We
 * aggregate into folio_page_stats via firstOrCreate + atomic increment (never
 * raw SQL — table-prefix + sqlite traps) and always answer 204.
 *
 * PRIVACY: only a page number + a dwell duration are recorded — never a cookie,
 * IP, or any visitor identifier.
 */
class FolioMetricsController extends Controller
{
    public function store(Request $request, int $storeId): Response
    {
        // Only aggregate for a live folio store; anything else is silently
        // ignored (a beacon is fire-and-forget — never surface an error).
        if ((string) (store_options($storeId, 'store_template') ?: '') !== 'folio') {
            return response()->noContent();
        }

        $data = $request->validate([
            'page' => ['required', 'integer', 'min:1', 'max:500'],
            'ms' => ['required', 'integer', 'min:0', 'max:600000'],
        ]);

        // P1-17: the beacon answers 204 at once; the aggregate is written after the response is sent.
        $day = now()->toDateString();
        defer(function () use ($storeId, $day, $data): void {
            $stat = FolioPageStat::firstOrCreate([
                'store_id' => $storeId,
                'day' => $day,
                'page' => (int) $data['page'],
            ]);
            $stat->increment('views');
            $stat->increment('ms_total', (int) $data['ms']);
        });

        return response()->noContent();
    }
}
