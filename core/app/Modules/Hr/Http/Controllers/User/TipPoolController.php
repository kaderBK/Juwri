<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Http\Controllers\User;

use App\Models\Order;
use App\Models\StaffMember;
use App\Services\Backup\DatasetStreamWriter;
use App\Support\StoreClock;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;

/**
 * K4: tip pooling report. Aggregates tips per staff member over a date range for
 * the owner's restaurants, with a pooling rule (by orders / evenly) and a CSV
 * export. Money is rendered through the store's currency precision; the SUM is a
 * query-builder aggregate over the model (never a hardcoded qrtest_orders).
 */
class TipPoolController extends Controller
{
    public function index(Request $request)
    {
        $this->activeTheme = active_theme();
        $storeIds = dashboard_store_ids();

        // from/to are store-local days. The owner may run several stores in different
        // timezones, so each store's orders are bucketed by that store's own calendar
        // day (StoreClock), not the UTC clock. Default dates use the first store's day.
        $anchorStoreId = $storeIds[0] ?? null;
        $from = $request->query('from') ?: StoreClock::now($anchorStoreId)->subDays(30)->toDateString();
        $to = $request->query('to') ?: StoreClock::today($anchorStoreId);
        $rule = in_array($request->query('rule'), ['even', 'by_orders'], true) ? $request->query('rule') : 'by_orders';

        $rows = Order::whereIn('store_id', $storeIds)
            ->where(function ($query) use ($storeIds, $from, $to) {
                foreach ($storeIds as $storeId) {
                    $query->orWhere(fn ($storeQuery) => StoreClock::whereLocalDateBetween(
                        $storeQuery->where('store_id', $storeId), 'created_at', $storeId, $from, $to
                    ));
                }
            })
            ->where('tip_amount_minor', '>', 0)
            ->selectRaw('created_by_staff_id, COUNT(*) as orders_count, SUM('.Order::minorMoneySql('tip_amount').') as tips_total')
            ->groupBy('created_by_staff_id')
            ->toBase()
            ->get();

        $totalTips = (float) $rows->sum('tips_total');
        // The NULL created_by_staff_id row is the storefront/unassigned bucket — NOT a
        // staff member. Counting it as one diluted every real staff share and paid a
        // slice of the pool to nobody (2 waiters + storefront → each got totalTips/3,
        // and totalTips/3 was attributed to "Storefront / unassigned"). Split the pool
        // only across rows with a real staff id; the unassigned row gets a 0 share.
        $staffCount = max(1, $rows->filter(fn ($r) => $r->created_by_staff_id !== null)->count());

        $names = StaffMember::whereIn('id', $rows->pluck('created_by_staff_id')->filter()->all())
            ->pluck('name', 'id');

        $report = $rows->map(function ($r) use ($rule, $totalTips, $staffCount, $names) {
            return [
                'staff_id' => $r->created_by_staff_id,
                'staff' => $r->created_by_staff_id ? ($names[$r->created_by_staff_id] ?? ('Staff #'.$r->created_by_staff_id)) : ___('Storefront / unassigned'),
                'orders' => (int) $r->orders_count,
                'tips' => (float) $r->tips_total,
                // by_orders → each staff keeps the tips on their own orders;
                // even → the pool is split equally across contributing staff (the
                // unassigned bucket contributes to the pool but receives no share).
                'share' => $rule === 'even'
                    ? ($r->created_by_staff_id !== null ? round($totalTips / $staffCount, 2) : 0.0)
                    : (float) $r->tips_total,
            ];
        })->sortByDesc('tips')->values();

        if ($request->query('export') === 'csv') {
            return $this->exportCsv($report, $from, $to, $rule);
        }

        return view('owner.reports.tip-pool', compact('report', 'from', 'to', 'rule', 'totalTips'));
    }

    private function exportCsv($report, string $from, string $to, string $rule)
    {
        $filename = "tip-pool-{$from}_to_{$to}.csv";

        return response()->stream(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
            fputcsv($out, ['Staff', 'Orders', 'Tips', 'Pooled share'], escape: '\\');
            foreach ($report as $r) {
                // staff name is user-editable — neutralize spreadsheet formula injection.
                fputcsv($out, array_map([DatasetStreamWriter::class, 'csvCell'], [$r['staff'], $r['orders'], number_format($r['tips'], (int) currency_decimals(), '.', ''), number_format($r['share'], (int) currency_decimals(), '.', '')]), escape: '\\');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
