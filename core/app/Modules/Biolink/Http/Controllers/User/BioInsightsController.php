<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Http\Controllers\User;

use App\Services\Backup\DatasetStreamWriter;
use App\Services\FeatureAccess;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Biolink\Services\BioInsightsService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * bio-t15 Lane D — SPEC §7 D4: CSV export ONLY. A separate controller (not
 * BioLinkController) so this run never edits Lane A/C's controller file — the
 * same pattern BioTemplateController already uses for the AI surfaces.
 *
 * Sits on the SAME dashboard route group/middleware stack as `biolink.insights`
 * (`feature:biolink` kill-switch + staff.dashboard + tenant scoping via
 * ResolvesCurrentStore — the shared trait every owner-dashboard controller
 * uses, not a Lane-D-only file). On top of that shared gate, this export is
 * itself PRO (`bio_analytics_pro`, SPEC §8) — checked HERE, server-side, not
 * only hidden in the UI (SPEC §3/§8 "the UI lock is cosmetic and never the
 * only gate" — the same rule applies to every PRO surface, not just appearance).
 */
class BioInsightsController extends Controller
{
    use ResolvesCurrentStore;

    /** Same range vocabulary as BioLinkController::RANGES. */
    private const RANGES = ['24h', '7d', '30d', '90d'];

    public function export(Request $request, BioInsightsService $svc): StreamedResponse
    {
        $store = $this->currentRestaurant($request);

        abort_unless(FeatureAccess::storeAllows($store->id, 'bio_analytics_pro'), 403);

        $range = (string) $request->query('range', '7d');
        if (! in_array($range, self::RANGES, true)) {
            $range = '7d';
        }

        $filename = 'bio-insights-'.$store->slug.'-'.$range.'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($svc, $store, $range) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM so the CSV opens correctly (Arabic/RTL labels included) in spreadsheets.
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($out, ['Day', 'Block', 'Source', 'Device', 'Country', 'Referrer', 'Count'], escape: '\\');

            foreach ($svc->csvRows($store->id, $range) as $row) {
                fputcsv($out, array_map([DatasetStreamWriter::class, 'csvCell'], [
                    $row['day'],
                    $row['block_label'],
                    $row['source'],
                    $row['device'],
                    $row['country'],
                    $row['ref'],
                    $row['count'],
                ]),
                    escape: '\\');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
