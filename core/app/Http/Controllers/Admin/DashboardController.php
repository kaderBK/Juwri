<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Admin;

use App\Services\AdminDashboardService;
use App\Services\HostingHealthCard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Juwri\Core\Contracts\Billing\UsageReport;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Policies\Admin\PanelAbilities;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Payments\Models\Transaction;

/** Admin › Dashboard: platform totals, charts and the latest payments and restaurants. */
class DashboardController extends Controller
{
    /** How many recent payments / restaurants the side feeds show. */
    private const FEED_SIZE = 6;

    /** P1-13 13g: the merchant recurring revenue tiles (MRR / ARPU / churn / trial→paid) — platform analytics (ee). */
    public const MERCHANT_REVENUE_SLOT = 'platform.admin.merchant-revenue';

    /** P1-13 13g: the platform analytics teaser (NRR / net-MRR movement / at-risk) — platform analytics (ee). */
    public const ANALYTICS_TEASER_SLOT = 'platform.admin.analytics-teaser';

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            Gate::authorize(PanelAbilities::DASHBOARD);

            return $next($request);
        });
    }

    public function index(AdminDashboardService $dashboard): View
    {
        // The two feeds are not cached: admins expect them to move right after an action.
        $recentTransactions = Transaction::query()->with('user:id,name,image')
            ->whereIn('status', [Transaction::STATUS_SUCCESS, Transaction::STATUS_PENDING, 'failed'])
            ->whereHas('user')
            ->latest('id')->limit(self::FEED_SIZE)->get();

        $recentRestaurants = Store::query()->with('user:id,name')->latest('id')->limit(self::FEED_SIZE)->get();

        return view('admin.dashboard.index', array_merge($dashboard->overview(), [
            'recentTransactions' => $recentTransactions,
            'recentRestaurants' => $recentRestaurants,
            'charts' => $dashboard->charts(30),
            // P6-138: hosting health warnings, platform admin only (the route already requires the DASHBOARD ability).
            'hostingHealth' => Gate::allows(PanelAbilities::DASHBOARD) ? app(HostingHealthCard::class)->get() : null,
            // Abuse Guard P7 (7.6): top-5 tenants by public requests today.
            'topUsageTenants' => app(UsageReport::class)->topByMetric('requests_public', 5, 1),
            // M9: merchant recurring revenue (MRR / ARPU / churn / trial→paid). Not shown without platform billing.
            'merchantRevenue' => Hooks::answer(self::MERCHANT_REVENUE_SLOT),
        ], ($teaser = Hooks::answer(self::ANALYTICS_TEASER_SLOT)) === null ? [] : [
            // Analytics teaser (NRR / net-MRR movement / at-risk), cached, links to the full page.
            'analyticsTeaser' => $teaser,
        ]));
    }

    /** Chart series for another period (the dashboard's range picker). */
    public function chartData(Request $request, AdminDashboardService $dashboard): JsonResponse
    {
        return response()->json($dashboard->charts((int) $request->query('days', 30)));
    }
}
