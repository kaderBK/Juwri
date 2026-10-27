<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Http\Controllers\User;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreView;
use App\Services\Backup\DatasetStreamWriter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\UserSettings;
use Juwri\Core\Support\Sql;
use Juwri\Modules\Analytics\Services\BehaviorAnalyticsService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuItemEvent;
use Juwri\Modules\Menu\Services\MenuEngineeringService;

class AnalyticsController extends Controller
{
    public function __construct()
    {
        $this->activeTheme = active_theme();
    }

    /** SA1: menu-engineering matrix (popularity × margin) for an owned restaurant. */
    public function menuEngineering(Request $request, int $storeId)
    {
        abort_unless(in_array($storeId, dashboard_store_ids(), true), 404); // owner-scoped

        return response()->json(MenuEngineeringService::matrix(
            $storeId,
            $request->query('from'),
            $request->query('to'),
        ));
    }

    public function index(Request $request)
    {
        // P1-4: one period model. Redirect legacy ?range= to the shared ?period=,
        // then resolve the period (session-persisted) and derive analytics' bucket.
        if ($request->has('range') && ! $request->has('period')) {
            $legacy = ['7_days' => '7d', '30_days' => '30d', 'this_month' => 'month'];
            $p = $legacy[$request->get('range')] ?? 'month';

            return redirect()->to($request->fullUrlWithQuery(['range' => null, 'period' => $p]));
        }
        $__period = resolve_dashboard_period();
        $period = $__period['period'];
        $compare = $__period['compare'];
        $rangeFrom = $__period['from'];
        $rangeTo = $__period['to'];
        $periodToRange = ['today' => '7_days', '7d' => '7_days', '30d' => '30_days', 'month' => 'this_month', 'custom' => 'this_month'];

        $owner = dashboard_owner();
        // Follow the sidebar store/branch switcher for consistency with the dashboard.
        // ?scope=all restores the owner-wide view. (store_page_views carry no branch_id, so
        // branch scoping applies to orders only — mirrors DashboardController.)
        $accessibleIds = dashboard_store_ids();
        $scopeAll = request('scope') === 'all';
        $activeStore = $scopeAll ? null : dashboard_current_store();
        $activeBranch = $scopeAll ? null : dashboard_current_branch();
        $storeIds = $activeStore ? [(int) $activeStore->id] : $accessibleIds;
        $branchId = $activeBranch?->id;

        if (empty($storeIds)) {
            return view('owner.reports.analytics', [
                'totalScans' => 0,
                'uniqueVisitors' => 0,
                'repeatScanners' => 0,
                'repeatRate' => 0,
                'hourlyData' => [],
                'dailyData' => [],
                'deviceData' => [],
                'langData' => [],
                'range' => '7_days',
                'goalType' => null,
                'goalValue' => null,
                'goalProgressPct' => null,
                'activeStore' => $activeStore,
                'activeBranch' => $activeBranch,
                'scopeAll' => $scopeAll,
                'period' => $period,
                'compare' => $compare,
                'rangeFrom' => $rangeFrom,
                'rangeTo' => $rangeTo,
                // H13: defaults for the "Applied window" line on the no-stores path.
                'analyticsWindowFrom' => Carbon::now()->subDays(7)->toDateString(),
                'analyticsWindowTo' => Carbon::now()->toDateString(),
            ]);
        }

        $range = $periodToRange[$period] ?? '7_days';
        $startDate = null;
        $prevStartDate = null;
        $prevEndDate = null;

        // Ranges follow the active store's timezone (all-stores view stays on the
        // platform tz). store_page_views.date is written with date('Y-m-d H:i:s') — an
        // app-timezone wall-clock — so store-local bounds are converted back to the
        // app timezone before they are used as query bounds; the human-facing
        // window labels keep the store-local calendar date.
        $appTz = config('app.timezone') ?: 'UTC';
        $tz = $activeStore ? store_tz((int) $activeStore->id) : $appTz;
        $nowTz = Carbon::now($tz);
        $toAppTz = fn (Carbon $c) => $c->copy()->setTimezone($appTz);

        $startLocal = $nowTz->copy()->subDays(7)->startOfDay();
        if ($range == '7_days') {
            $startDate = $toAppTz($startLocal);
            $prevStartDate = $toAppTz($nowTz->copy()->subDays(14)->startOfDay());
            $prevEndDate = $toAppTz($nowTz->copy()->subDays(7)->startOfDay());
        } elseif ($range == '30_days') {
            $startLocal = $nowTz->copy()->subDays(30)->startOfDay();
            $startDate = $toAppTz($startLocal);
            $prevStartDate = $toAppTz($nowTz->copy()->subDays(60)->startOfDay());
            $prevEndDate = $toAppTz($nowTz->copy()->subDays(30)->startOfDay());
        } elseif ($range == 'this_month') {
            $startLocal = $nowTz->copy()->startOfMonth();
            $startDate = $toAppTz($startLocal);
            $prevStartDate = $toAppTz($nowTz->copy()->subMonth()->startOfMonth());
            $prevEndDate = $toAppTz($nowTz->copy()->startOfMonth());
        }

        // H13: the window the figures actually cover, surfaced on the page so an
        // operator can tell at a glance which slice they are reading — in the
        // store's own calendar. $range is always one of the three buckets above.
        $analyticsWindowFrom = $startLocal->toDateString();
        $analyticsWindowTo = $nowTz->toDateString();

        // Base query
        $baseQuery = StoreView::whereIn('store_id', $storeIds);
        if ($startDate) {
            $baseQuery->where('date', '>=', $startDate);
        }

        // 1. Summary Cards Metrics
        $totalScans = (clone $baseQuery)->count();
        $uniqueVisitors = (clone $baseQuery)->distinct('ip')->count('ip');

        // Previous period scan count for trend arrows
        $prevBaseQuery = StoreView::whereIn('store_id', $storeIds);
        if ($prevStartDate) {
            $prevBaseQuery->where('date', '>=', $prevStartDate)->where('date', '<', $prevEndDate);
        }
        $prevTotalScans = (clone $prevBaseQuery)->count();
        $prevUniqueVisitors = (clone $prevBaseQuery)->distinct('ip')->count('ip');

        // Repeat scanners (IPs with > 1 scan in range)
        $repeatQuery = StoreView::whereIn('store_id', $storeIds);
        if ($startDate) {
            $repeatQuery->where('date', '>=', $startDate);
        }
        $repeatScanners = $repeatQuery->select('ip')
            ->groupBy('ip')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $repeatRate = $uniqueVisitors > 0 ? round(($repeatScanners / $uniqueVisitors) * 100, 1) : 0;

        $prevRepeatQuery = StoreView::whereIn('store_id', $storeIds);
        if ($prevStartDate) {
            $prevRepeatQuery->where('date', '>=', $prevStartDate)->where('date', '<', $prevEndDate);
        }
        $prevRepeatScanners = $prevRepeatQuery->select('ip')
            ->groupBy('ip')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        $prevRepeatRate = $prevUniqueVisitors > 0 ? round(($prevRepeatScanners / $prevUniqueVisitors) * 100, 1) : 0;

        // DB dialect helpers (Juwri\Core\Support\Sql) for the date expressions
        // Bucket scans by the store's local hour/day. store_page_views.date is an app-tz
        // wall-clock, so shift it by the store↔platform offset (in minutes, so
        // half-hour zones like Asia/Kolkata work) before extracting hour/day. The
        // offset is 0 when the store is on the platform timezone, leaving the SQL
        // byte-identical. $offsetMin is derived from tz math (int), never user input.
        $offsetMin = Carbon::now($tz)->utcOffset() - Carbon::now($appTz)->utcOffset();
        $shifted = Sql::addMinutes('date', $offsetMin);
        $hourExpr = Sql::hour($shifted);
        $dateExpr = Sql::date($shifted);

        // 2. Hourly Breakdown
        $hourlyRaw = (clone $baseQuery)
            ->select(DB::raw("{$hourExpr} as hour_val"), DB::raw('count(*) as count'))
            ->groupBy('hour_val')
            ->orderBy('hour_val')
            ->pluck('count', 'hour_val')
            ->toArray();

        // Fill all 24 hours
        $hourlyData = [];
        for ($i = 0; $i < 24; $i++) {
            $key = sprintf('%02d', $i);
            $hourlyData[$key.':00'] = $hourlyRaw[$key] ?? $hourlyRaw[$i] ?? 0;
        }

        // 3. Daily Breakdown (Trend line)
        $dailyRaw = (clone $baseQuery)
            ->select(DB::raw("{$dateExpr} as date_val"), DB::raw('count(*) as count'))
            ->groupBy('date_val')
            ->orderBy('date_val')
            ->pluck('count', 'date_val')
            ->toArray();

        // Fill date range
        $dailyData = [];
        $daysToFill = $range == 'this_month' ? $nowTz->day : ($range == '30_days' ? 30 : 7);
        // Enumerate store-local days so the labels match the store-local dateExpr buckets.
        $startIterator = clone $startLocal;

        for ($i = 0; $i <= $daysToFill; $i++) {
            $dateString = $startIterator->toDateString();
            if ($dateString > $nowTz->toDateString()) {
                break;
            }
            $dailyData[Carbon::parse($dateString)->format('M d')] = $dailyRaw[$dateString] ?? 0;
            $startIterator->addDay();
        }

        // 4. Device Breakdown
        $deviceData = (clone $baseQuery)
            ->select('device', DB::raw('count(*) as count'))
            ->groupBy('device')
            ->pluck('count', 'device')
            ->toArray();
        if (isset($deviceData[''])) {
            $deviceData['Unknown'] = ($deviceData['Unknown'] ?? 0) + $deviceData[''];
            unset($deviceData['']);
        }

        // 5. Language Breakdown
        $langData = (clone $baseQuery)
            ->select('lang', DB::raw('count(*) as count'))
            ->groupBy('lang')
            ->pluck('count', 'lang')
            ->toArray();
        if (isset($langData[''])) {
            $langData['Unknown'] = ($langData['Unknown'] ?? 0) + $langData[''];
            unset($langData['']);
        }

        // 5b. Source / UTM Breakdown (#54)
        $sourceData = [];
        if (Schema::hasColumn('store_page_views', 'source')) {
            $rawSource = (clone $baseQuery)
                ->select('source', DB::raw('count(*) as count'))
                ->groupBy('source')
                ->pluck('count', 'source')
                ->toArray();
            foreach ($rawSource as $src => $cnt) {
                $label = $src ?: 'Direct';
                $sourceData[$label] = ($sourceData[$label] ?? 0) + $cnt;
            }
            arsort($sourceData);
        }

        // 6. Order / Sales Metrics
        // H13: hydrate ONLY the revenue-counted orders, and with the relations
        // Order::computeMoney() walks. Order::items() force-loads
        // menu/itemExtras/variant/combo but NOT itemExtras.extra, which
        // computeMoney() reads for every option line — so with(['items']) alone
        // still N+1s. "Successful" moves from a PHP filter to the SQL
        // scopeRevenueCounted() (H10's shared definition: status = completed OR
        // is_paid), which is byte-identical to the old countsAsRevenue() PHP
        // filter, so no reported figure changes. $placedCount is a SQL COUNT.
        $orderScope = fn () => Order::whereIn('store_id', $storeIds)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate));

        $placedCount = $orderScope()->count();
        $successfulOrders = $orderScope()->revenueCounted()
            ->with(['items.menu', 'items.itemExtras.extra', 'items.variant'])
            ->get();

        $totalRevenue = $successfulOrders->sum('total');
        $ordersCount = $successfulOrders->count();
        $aov = $ordersCount > 0 ? Money::round($totalRevenue / $ordersCount, 2) : 0;
        $conversionRate = $totalScans > 0 ? round(($ordersCount / $totalScans) * 100, 1) : 0;

        // Previous period order metrics.
        // H13: this query previously had NO eager load at all and then summed the
        // `total` accessor — five queries per order across the whole window.
        $prevSuccessfulOrders = Order::whereIn('store_id', $storeIds)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($prevStartDate, fn ($q) => $q->where('created_at', '>=', $prevStartDate)
                ->where('created_at', '<', $prevEndDate))
            ->revenueCounted()
            ->with(['items.menu', 'items.itemExtras.extra', 'items.variant'])
            ->get();
        $prevTotalRevenue = $prevSuccessfulOrders->sum('total');
        $prevOrdersCount = $prevSuccessfulOrders->count();
        $prevAov = $prevOrdersCount > 0 ? Money::round($prevTotalRevenue / $prevOrdersCount, 2) : 0;
        $prevConversionRate = $prevTotalScans > 0 ? round(($prevOrdersCount / $prevTotalScans) * 100, 1) : 0;

        // Delta helper closure
        $trendDelta = function ($current, $previous) {
            if ($previous == 0) {
                return ['pct' => null, 'dir' => 'flat'];
            }
            $pct = round((($current - $previous) / $previous) * 100, 1);

            return ['pct' => abs($pct), 'dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat')];
        };
        $scansDelta = $trendDelta($totalScans, $prevTotalScans);
        $uniqueVisitorsDelta = $trendDelta($uniqueVisitors, $prevUniqueVisitors);
        $repeatScannersDelta = $trendDelta($repeatScanners, $prevRepeatScanners);
        $repeatRateDelta = $trendDelta($repeatRate, $prevRepeatRate);
        $revenueDelta = $trendDelta($totalRevenue, $prevTotalRevenue);
        $ordersDelta = $trendDelta($ordersCount, $prevOrdersCount);
        $aovDelta = $trendDelta($aov, $prevAov);
        $convDelta = $trendDelta($conversionRate, $prevConversionRate);

        // Peak order hours
        $hourlyOrdersRaw = [];
        foreach ($successfulOrders as $o) {
            if ($o->created_at) {
                // created_at is app-tz; peak-hours are read in the store's local clock.
                $hour = $o->created_at->copy()->setTimezone($tz)->format('H');
                $hourlyOrdersRaw[$hour] = ($hourlyOrdersRaw[$hour] ?? 0) + 1;
            }
        }
        $hourlyOrdersData = [];
        for ($i = 0; $i < 24; $i++) {
            $key = sprintf('%02d', $i);
            $hourlyOrdersData[$key.':00'] = $hourlyOrdersRaw[$key] ?? $hourlyOrdersRaw[$i] ?? 0;
        }

        // 7. Item Performance Aggregations
        $menuItems = Menu::whereIn('store_id', $storeIds)
            ->select('id', 'name', 'price_minor', 'currency_code')
            ->get();

        $eventsQuery = MenuItemEvent::whereIn('store_id', $storeIds);
        if ($startDate) {
            $eventsQuery->where('created_at', '>=', $startDate);
        }
        $eventCounts = $eventsQuery->select('menu_id', 'type', DB::raw('count(*) as count'))
            ->groupBy('menu_id', 'type')
            ->get()
            ->groupBy('menu_id');

        // H13: $successfulOrders is now the same SQL predicate, already loaded.
        $successfulOrderIds = $successfulOrders->pluck('id')->toArray();

        $orderItemCounts = OrderItem::whereIn('order_id', $successfulOrderIds)
            ->select('item_id', DB::raw('sum(quantity) as total_qty'))
            ->groupBy('item_id')
            ->pluck('total_qty', 'item_id')
            ->toArray();

        $itemPerformance = [];
        foreach ($menuItems as $item) {
            $itemEvents = $eventCounts->get($item->id) ?? collect();
            $views = $itemEvents->where('type', 'view')->first()->count ?? 0;
            $addToCarts = $itemEvents->where('type', 'add_to_cart')->first()->count ?? 0;
            $itemOrderedQty = $orderItemCounts[$item->id] ?? 0;

            $cartConv = $views > 0 ? round(($addToCarts / $views) * 100, 1) : 0;
            $orderConv = $views > 0 ? round(($itemOrderedQty / $views) * 100, 1) : 0;

            $itemPerformance[] = [
                'id' => $item->id,
                'name' => $item->name,
                'price' => $item->price,
                'views' => $views,
                'addToCarts' => $addToCarts,
                'orders' => $itemOrderedQty,
                'cartConv' => $cartConv,
                'orderConv' => $orderConv,
            ];
        }

        usort($itemPerformance, function ($a, $b) {
            if ($a['orders'] === $b['orders']) {
                return $b['views'] <=> $a['views'];
            }

            return $b['orders'] <=> $a['orders'];
        });

        $categoryHeatmap = $this->getCategoryHeatmapData($storeIds, $startDate);

        // 9. Conversion Funnel Calculations
        $funnelBrowse = MenuItemEvent::whereIn('store_id', $storeIds)
            ->where('type', 'view');
        if ($startDate) {
            $funnelBrowse->where('created_at', '>=', $startDate);
        }
        $browseCount = $funnelBrowse->count();

        $funnelCart = MenuItemEvent::whereIn('store_id', $storeIds)
            ->where('type', 'add_to_cart');
        if ($startDate) {
            $funnelCart->where('created_at', '>=', $startDate);
        }
        $cartCount = $funnelCart->count();

        // H13: $placedCount is computed as a SQL COUNT next to $successfulOrders
        // now; the dead `$orders->count()` here is removed with the hydration.
        $paidCount = $ordersCount;

        // MC-41: revenue is now grouped by each order's STAMPED currency
        // (revenueByCurrency below), so a multi-store owner whose stores span
        // currencies no longer sees OMR + USD blindly summed under one symbol.
        // These two scalars remain the store-native display for the MENU-PRICE
        // table (menu prices carry no currency stamp) and the single-currency
        // summary cards — byte-identical to the pre-MC-41 first-store sourcing.
        $currencySymbol = store_options($storeIds[0], 'currency_sign', '$');
        $currencyDecimals = currency_decimals(store_options($storeIds[0], 'currency_code') ?: null);
        $revenueByCurrency = $this->revenueByCurrency($successfulOrders, (int) $storeIds[0]);

        $goalType = $owner ? UserSettings::legacy((int) $owner->id, 'dashboard_goal_type') : null;
        $goalValue = $owner ? UserSettings::legacy((int) $owner->id, 'dashboard_goal_value') : null;
        $legacyScanGoal = $owner ? UserSettings::legacy((int) $owner->id, 'scan_goal_month') : null;

        if (! $goalType && $legacyScanGoal) {
            $goalType = 'scans';
            $goalValue = $legacyScanGoal;
        }

        $goalValue = $goalValue !== null ? (float) $goalValue : null;
        $goalCurrentValue = match ($goalType) {
            'revenue' => $totalRevenue,
            'orders' => $ordersCount,
            'scans' => $totalScans,
            default => null,
        };
        $goalProgressPct = ($goalValue && $goalCurrentValue !== null && $goalValue > 0)
            ? min(100, round(($goalCurrentValue / $goalValue) * 100))
            : null;

        // SA1: restaurants for the menu-engineering matrix selector.
        $menuRestaurants = Store::whereIn('id', $storeIds)->get(['id', 'title']);

        // D10: surface the already-collected scan-behavior signals (dwell time,
        // exit sections, language + traffic-source split) — collected since the
        // post_view_behavior migration but never shown.
        $behavior = BehaviorAnalyticsService::summary($storeIds, $rangeFrom, $rangeTo);
        $behaviorSources = BehaviorAnalyticsService::sourceSplit($storeIds, $rangeFrom, $rangeTo);

        return view('owner.reports.analytics', compact(
            'behavior',
            'behaviorSources',
            'menuRestaurants',
            'totalScans',
            'uniqueVisitors',
            'repeatScanners',
            'repeatRate',
            'hourlyData',
            'dailyData',
            'deviceData',
            'langData',
            'range',
            'totalRevenue',
            'ordersCount',
            'aov',
            'conversionRate',
            'hourlyOrdersData',
            'currencySymbol',
            'currencyDecimals',
            'revenueByCurrency',
            'itemPerformance',
            'categoryHeatmap',
            'browseCount',
            'cartCount',
            'placedCount',
            'paidCount',
            'scansDelta',
            'uniqueVisitorsDelta',
            'repeatScannersDelta',
            'repeatRateDelta',
            'revenueDelta',
            'ordersDelta',
            'aovDelta',
            'convDelta',
            'sourceData',
            'goalType',
            'goalValue',
            'goalProgressPct',
            'activeStore',
            'activeBranch',
            'scopeAll',
            'period',
            'compare',
            'rangeFrom',
            'rangeTo',
            'analyticsWindowFrom',
            'analyticsWindowTo'
        ));
    }

    private function getAnalyticsData($user, $range)
    {
        // Follow the sidebar store/branch switcher (same as index()).
        $accessibleIds = dashboard_store_ids();
        $scopeAll = request('scope') === 'all';
        $activeStore = $scopeAll ? null : dashboard_current_store();
        $activeBranch = $scopeAll ? null : dashboard_current_branch();
        $storeIds = $activeStore ? [(int) $activeStore->id] : ($accessibleIds ?: $user->stores()->pluck('id')->toArray());
        $branchId = $activeBranch?->id;
        if (empty($storeIds)) {
            return null;
        }

        // H13: a window is MANDATORY. An unrecognised $range used to leave
        // $startDate null, which dropped EVERY date bound in this method and
        // hydrated the store's entire order history (plus its whole item graph)
        // to compute one sum. Reachable today via
        // GET /user/analytics/export?range=<anything>.
        // Window follows the active store's timezone; bounds are normalised to the
        // app timezone for querying (store_page_views.date is an app-tz wall-clock), while
        // the CSV/page labels keep the store-local calendar date. See index().
        $appTz = config('app.timezone') ?: 'UTC';
        $tz = $activeStore ? store_tz((int) $activeStore->id) : $appTz;
        $nowTz = Carbon::now($tz);
        $startLocal = match ($range) {
            '30_days' => $nowTz->copy()->subDays(30)->startOfDay(),
            'this_month' => $nowTz->copy()->startOfMonth(),
            default => $nowTz->copy()->subDays(7)->startOfDay(),
        };
        $startDate = $startLocal->copy()->setTimezone($appTz);

        $baseQuery = StoreView::whereIn('store_id', $storeIds)->where('date', '>=', $startDate);

        $totalScans = (clone $baseQuery)->count();
        $uniqueVisitors = (clone $baseQuery)->distinct('ip')->count('ip');

        $repeatScanners = StoreView::whereIn('store_id', $storeIds)
            ->where('date', '>=', $startDate)
            ->select('ip')
            ->groupBy('ip')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $repeatRate = $uniqueVisitors > 0 ? round(($repeatScanners / $uniqueVisitors) * 100, 1) : 0;

        // H13: same hydration fix as index() — revenue-counted only, with the
        // relations computeMoney() walks; $startDate is now non-null.
        $orderScope = fn () => Order::whereIn('store_id', $storeIds)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('created_at', '>=', $startDate);

        $placedCount = $orderScope()->count();
        $successfulOrders = $orderScope()->revenueCounted()
            ->with(['items.menu', 'items.itemExtras.extra', 'items.variant'])
            ->get();

        $totalRevenue = $successfulOrders->sum('total');
        $ordersCount = $successfulOrders->count();
        $aov = $ordersCount > 0 ? Money::round($totalRevenue / $ordersCount, 2) : 0;
        $conversionRate = $totalScans > 0 ? round(($ordersCount / $totalScans) * 100, 1) : 0;

        // Item performance
        $menuItems = Menu::whereIn('store_id', $storeIds)
            ->select('id', 'name', 'price_minor', 'currency_code')
            ->get();

        $eventsQuery = MenuItemEvent::whereIn('store_id', $storeIds);
        $eventsQuery->where('created_at', '>=', $startDate); // H13: window mandatory
        $eventCounts = $eventsQuery->select('menu_id', 'type', DB::raw('count(*) as count'))
            ->groupBy('menu_id', 'type')
            ->get()
            ->groupBy('menu_id');

        $successfulOrderIds = $successfulOrders->pluck('id')->toArray();

        $orderItemCounts = OrderItem::whereIn('order_id', $successfulOrderIds)
            ->select('item_id', DB::raw('sum(quantity) as total_qty'))
            ->groupBy('item_id')
            ->pluck('total_qty', 'item_id')
            ->toArray();

        $itemPerformance = [];
        foreach ($menuItems as $item) {
            $itemEvents = $eventCounts->get($item->id) ?? collect();
            $views = $itemEvents->where('type', 'view')->first()->count ?? 0;
            $addToCarts = $itemEvents->where('type', 'add_to_cart')->first()->count ?? 0;
            $itemOrders = $orderItemCounts[$item->id] ?? 0;

            $cartConv = $views > 0 ? round(($addToCarts / $views) * 100, 1) : 0;
            $orderConv = $views > 0 ? round(($itemOrders / $views) * 100, 1) : 0;

            $itemPerformance[] = [
                'name' => $item->name,
                'price' => $item->price,
                'views' => $views,
                'addToCarts' => $addToCarts,
                'orders' => $itemOrders,
                'cartConv' => $cartConv,
                'orderConv' => $orderConv,
            ];
        }

        usort($itemPerformance, function ($a, $b) {
            if ($a['orders'] === $b['orders']) {
                return $b['views'] <=> $a['views'];
            }

            return $b['orders'] <=> $a['orders'];
        });

        $funnelBrowse = MenuItemEvent::whereIn('store_id', $storeIds)
            ->where('type', 'view');
        $funnelBrowse->where('created_at', '>=', $startDate); // H13: window mandatory
        $browseCount = $funnelBrowse->count();

        $funnelCart = MenuItemEvent::whereIn('store_id', $storeIds)
            ->where('type', 'add_to_cart');
        $funnelCart->where('created_at', '>=', $startDate); // H13: window mandatory
        $cartCount = $funnelCart->count();

        return [
            'totalScans' => $totalScans,
            'uniqueVisitors' => $uniqueVisitors,
            'repeatRate' => $repeatRate,
            'totalRevenue' => $totalRevenue,
            'ordersCount' => $ordersCount,
            'aov' => $aov,
            'conversionRate' => $conversionRate,
            'itemPerformance' => $itemPerformance,
            'categoryHeatmap' => $this->getCategoryHeatmapData($storeIds, $startDate),
            // Store-native display for the menu-price table + single-currency
            // summary; revenue itself ships grouped in 'revenueByCurrency'.
            'currencySymbol' => store_options($storeIds[0], 'currency_sign', '$'),
            // S7: carried so the CSV export can fall back to the ISO code for a
            // sign a spreadsheet can't show (⃁, ﷼, ₹, د.إ).
            'currencyCode' => (string) (store_options($storeIds[0], 'currency_code') ?: config('settings.currency_code', '')),
            'currencyDecimals' => currency_decimals(store_options($storeIds[0], 'currency_code') ?: null),
            'revenueByCurrency' => $this->revenueByCurrency($successfulOrders, (int) $storeIds[0]),
            'browseCount' => $browseCount,
            'cartCount' => $cartCount,
            'placedCount' => $placedCount,
            'paidCount' => $ordersCount,
            // H13: the window the figures actually cover, for the CSV + the page —
            // in the store's own calendar.
            'windowFrom' => $startLocal->toDateString(),
            'windowTo' => $nowTz->toDateString(),
        ];
    }

    /**
     * MC-41: group revenue-counted orders by their STAMPED currency (legacy
     * unstamped rows → the platform base), returning a display-ready map keyed
     * by ISO code, highest revenue first. Single-currency owners get exactly one
     * entry whose revenue == the blind sum('total') — the byte-identical pin.
     *
     * @param  Collection<int, Order>  $orders  hydrated revenue-counted orders
     * @return array<string, array{code:string,symbol:string,decimals:int,revenue:float,orders:int}>
     */
    private function revenueByCurrency($orders, int $fallbackStoreId): array
    {
        // Legacy unstamped rows: their own store's currency (an order is always in its store's money), else the
        // platform's — never the dashboard's working-store display swap.
        $base = platform_currency();
        $out = [];
        foreach ($orders->groupBy(fn ($o) => strtoupper((string) ($o->currency_code ?: ($o->store_id ? store_currency((int) $o->store_id) : $base)))) as $code => $group) {
            // Symbol from a store that actually booked revenue in this currency
            // (a store carries one currency); falls back to the owner's first
            // store so the single-currency symbol matches the summary cards.
            $sampleStoreId = (int) ($group->first()->store_id ?? $fallbackStoreId);
            $out[$code] = [
                'code' => $code,
                'symbol' => store_options($sampleStoreId, 'currency_sign', '$'),
                'decimals' => currency_decimals($code),
                'revenue' => (float) $group->sum('total'),
                'orders' => $group->count(),
            ];
        }
        uasort($out, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return $out;
    }

    private function getCategoryHeatmapData($storeIds, $startDate)
    {
        $categoryItems = MenuCategory::whereIn('store_id', $storeIds)
            ->select('id', 'name')
            ->with(['menus:id,category_id'])
            ->get();

        // N+1 fix: aggregate menu-item events for EVERY item across all categories in
        // ONE grouped query (menu_id + type), instead of a query per category.
        $allItemIds = $categoryItems->flatMap(fn ($c) => $c->menus->pluck('id'))->unique()->values()->all();
        $eventsByItem = []; // [menu_id][type] => count
        if (! empty($allItemIds)) {
            $q = MenuItemEvent::whereIn('menu_id', $allItemIds);
            if ($startDate) {
                $q->where('created_at', '>=', $startDate);
            }
            foreach ($q->select('menu_id', 'type', DB::raw('count(*) as count'))
                ->groupBy('menu_id', 'type')->get() as $row) {
                $eventsByItem[$row->menu_id][$row->type] = (int) $row->count;
            }
        }

        $categoryPerformance = [];
        $maxCategoryViews = 0;
        $maxCategoryCarts = 0;

        foreach ($categoryItems as $cat) {
            $itemIds = $cat->menus->pluck('id')->toArray();
            if (empty($itemIds)) {
                $categoryPerformance[] = [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'views' => 0,
                    'carts' => 0,
                    'view_intensity' => 0,
                    'cart_intensity' => 0,
                ];

                continue;
            }

            $views = 0;
            $carts = 0;
            foreach ($itemIds as $iid) {
                $views += $eventsByItem[$iid]['view'] ?? 0;
                $carts += $eventsByItem[$iid]['add_to_cart'] ?? 0;
            }

            if ($views > $maxCategoryViews) {
                $maxCategoryViews = $views;
            }
            if ($carts > $maxCategoryCarts) {
                $maxCategoryCarts = $carts;
            }

            $categoryPerformance[] = [
                'id' => $cat->id,
                'name' => $cat->name,
                'views' => $views,
                'carts' => $carts,
            ];
        }

        foreach ($categoryPerformance as &$cat) {
            $cat['view_intensity'] = $maxCategoryViews > 0 ? round(($cat['views'] / $maxCategoryViews) * 100) : 0;
            $cat['cart_intensity'] = $maxCategoryCarts > 0 ? round(($cat['carts'] / $maxCategoryCarts) * 100) : 0;
        }
        unset($cat);

        usort($categoryPerformance, function ($a, $b) {
            return $b['views'] <=> $a['views'];
        });

        return $categoryPerformance;
    }

    public function exportCsv(Request $request)
    {
        $user = $request->user();
        // Accept the shared ?period= model; still honour a legacy ?range= for BC.
        $periodToRange = ['today' => '7_days', '7d' => '7_days', '30d' => '30_days', 'month' => 'this_month', 'custom' => 'this_month'];
        // H13: `?range=` is raw request input. Anything outside this set used to
        // fall through getAnalyticsData()'s if/elseif chain and produce an
        // ALL-TIME export. Belt (this whitelist) and braces (the mandatory window
        // in getAnalyticsData).
        $allowedRanges = ['7_days', '30_days', 'this_month'];
        if ($request->filled('range') && in_array((string) $request->get('range'), $allowedRanges, true)) {
            $range = (string) $request->get('range');
        } else {
            $range = $periodToRange[resolve_dashboard_period()['period']] ?? '7_days';
        }
        $data = $this->getAnalyticsData($user, $range);

        if (! $data) {
            return back();
        }

        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=analytics_report_'.$range.'_'.now()->format('Ymd').'.csv',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        // Menu-price decimals follow the store-native currency (3 for
        // OMR/KWD/JOD/BHD/LYD/TND). Revenue no longer inherits this single
        // symbol: the "Revenue by Currency" section below carries each currency's
        // own code + decimals from the orders' stamped currency_code (MC-41).
        $decimals = $data['currencyDecimals'] ?? 2;

        // S7: a CSV opens in spreadsheets with no webfont — an undrawable currency
        // sign shows as the ISO code instead of mojibake/tofu ('$' stays '$').
        $csvSymbol = currency_sign_safe($data['currencySymbol'] ?? '$', (string) ($data['currencyCode'] ?? ''));

        $callback = function () use ($data, $range, $decimals, $csvSymbol) {
            $file = fopen('php://output', 'w');

            // UTF-8 BOM
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Section 1: Overview
            fputcsv($file, ['Analytics Report Overview'], escape: '\\');
            fputcsv($file, ['Range', str_replace('_', ' ', ucfirst($range))], escape: '\\');
            // H13: the resolved window the numbers actually cover (a bad ?range=
            // can no longer silently produce an all-time export).
            fputcsv($file, ['Window', ($data['windowFrom'] ?? '').' .. '.($data['windowTo'] ?? '')], escape: '\\');
            fputcsv($file, ['Generated At', now()->toDateTimeString()], escape: '\\');
            fputcsv($file, [], escape: '\\');

            // Section 2: Summary Metrics
            fputcsv($file, ['Summary Metrics'], escape: '\\');
            fputcsv($file, ['Metric', 'Value'], escape: '\\');
            fputcsv($file, ['Total Scans', $data['totalScans']], escape: '\\');
            fputcsv($file, ['Unique Visitors', $data['uniqueVisitors']], escape: '\\');
            fputcsv($file, ['Repeat Visitor Rate (%)', $data['repeatRate'].'%'], escape: '\\');
            fputcsv($file, ['Total Revenue', $csvSymbol.number_format($data['totalRevenue'], $decimals)], escape: '\\');
            fputcsv($file, ['Orders Count', $data['ordersCount']], escape: '\\');
            fputcsv($file, ['Average Order Value (AOV)', $csvSymbol.number_format($data['aov'], $decimals)], escape: '\\');
            fputcsv($file, ['Conversion Rate (Scans -> Orders) (%)', $data['conversionRate'].'%'], escape: '\\');
            fputcsv($file, [], escape: '\\');

            // Section 2b (MC-41): revenue broken out per stamped currency, so a
            // multi-currency owner reads OMR and USD as separate lines instead of
            // one blindly-summed figure. Single-currency owners get one row whose
            // Revenue equals the Total Revenue above.
            fputcsv($file, ['Revenue by Currency'], escape: '\\');
            fputcsv($file, ['Currency', 'Revenue', 'Orders'], escape: '\\');
            foreach (($data['revenueByCurrency'] ?? []) as $cur) {
                fputcsv($file, [
                    DatasetStreamWriter::csvCell($cur['code']),
                    DatasetStreamWriter::csvCell(currency_sign_safe($cur['symbol'], $cur['code']).number_format($cur['revenue'], $cur['decimals'])),
                    $cur['orders'],
                ],
                    escape: '\\');
            }
            fputcsv($file, [], escape: '\\');

            // Section 3: Item Performance
            fputcsv($file, ['Menu Item Performance'], escape: '\\');
            fputcsv($file, ['Item Name', 'Price', 'Views', 'Add to Carts', 'Orders', 'Detail-to-Cart Conversion (%)', 'Detail-to-Order Conversion (%)', 'Status'], escape: '\\');
            foreach ($data['itemPerformance'] as $item) {
                $status = 'Healthy';
                if ($item['orders'] == 0 && $item['views'] > 5) {
                    $status = 'Dead Item';
                } elseif ($item['orders'] > 0 && $item['orderConv'] >= 20) {
                    $status = 'Best Seller';
                }
                fputcsv($file, array_map([DatasetStreamWriter::class, 'csvCell'], [
                    $item['name'], // menu item name — neutralize formula injection
                    $csvSymbol.number_format($item['price'], $decimals),
                    $item['views'],
                    $item['addToCarts'],
                    $item['orders'],
                    $item['cartConv'].'%',
                    $item['orderConv'].'%',
                    $status,
                ]),
                    escape: '\\');
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
