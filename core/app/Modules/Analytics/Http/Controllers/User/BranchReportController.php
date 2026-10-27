<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Http\Controllers\User;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\PlanGate;
use Juwri\Modules\Analytics\Contracts\BranchComparison;
use Juwri\Modules\Analytics\Services\BranchReport;

class BranchReportController extends Controller
{
    private function ownerRestaurantIds(): array
    {
        return Store::where('user_id', auth()->id())->pluck('id')->toArray();
    }

    /**
     * Per-branch report for one restaurant over a date range. P1-13 13h (owner decision): the per-branch report is
     * Community — one branch at a time (`branch_id`, default the first branch); comparing every branch side by side
     * is ee ({@see BranchComparison}), and with it the page is exactly the consolidated comparison it was.
     */
    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id') ?: dashboard_current_store()?->id;

        if ($storeId && ! in_array((int) $storeId, $storeIds, true)) {
            abort(403);
        }

        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);

        $days = (int) $request->query('days', 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        // "Today" starts on the store's own clock (consistent with the dashboard /
        // analytics windows); created_at is an app-tz wall-clock, so the bound is
        // normalised back to the app timezone. Store on the platform tz → unchanged.
        $appTz = config('app.timezone') ?: 'UTC';
        $reportTz = $storeId ? store_tz((int) $storeId) : $appTz;
        $since = Carbon::today($reportTz)->subDays($days)->setTimezone($appTz);

        $comparison = app(BranchComparison::class);
        // Pricing follow-ups (PRICING.md §3): the side-by-side comparison needs a plan that includes multi-branch
        // reports (a plan without the key keeps it); otherwise the page is the one-branch-at-a-time report.
        $planAllows = PlanGate::allows($request->user(), PlanGate::BRANCH_REPORTS);
        $compare = $comparison->enabled() && $planAllows;
        $compareWithheld = $comparison->enabled() && ! $planAllows;
        $branches = collect();
        $branchId = null;
        $rows = collect();
        $totals = ['orders' => 0, 'revenue' => 0.0];

        if ($storeId) {
            $branches = Branch::where('store_id', (int) $storeId)->orderBy('position')->get();

            if ($compare) {
                $rows = $comparison->rows((int) $storeId, $branches, $since);
            } else {
                $branch = $branches->firstWhere('id', (int) $request->query('branch_id')) ?? $branches->first();
                $branchId = $branch?->id;
                $rows = $branch ? collect([BranchReport::row((int) $storeId, $branch, $since)]) : collect();
            }
            $totals = BranchReport::totals($rows);
        }

        return view('owner.branches.reports', compact(
            'stores', 'storeId', 'rows', 'totals', 'days', 'compare', 'branches', 'branchId', 'compareWithheld'
        ));
    }
}
