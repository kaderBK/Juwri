<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\User;

use App\Models\Customer;
use App\Models\MenuReview;
use App\Models\Order;
use App\Models\StoreView;
use App\Models\WaitlistEntry;
use App\Models\WebhookEndpoint;
use App\Services\DashboardAttentionService;
use App\Services\DashboardPeriodAggregates;
use App\Services\FeatureAccess;
use App\Support\SearchTerm;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Billing\UsageReport;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\Tenancy\TenantCache;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Settings\UserSettings;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\SafeRedirect;
use Juwri\Core\Support\Sql;
use Juwri\Modules\Ai\Contracts\AiProviderChain;
use Juwri\Modules\Content\Services\DevDocs\DocsCorpus;
use Juwri\Modules\Integrations\Models\WebhookDelivery;
use Juwri\Modules\Media\Services\StorageQuota;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\Dispute;
use Juwri\Modules\Payments\Models\Transaction;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class DashboardController extends Controller
{
    /** P1-13 13h: the peer-benchmark cohort row for (cohort, currency) — answered by the ee Analytics module. */
    public const PEER_BENCHMARK_SLOT = 'analytics.peer-benchmark.dashboard';

    /** Owner 2026-10-05: the per-branch comparison card (a chain tool) — answered by the ee Analytics module with ['view' => name, 'rows' => list]. */
    public const BRANCH_CARD_SLOT = 'analytics.branch-comparison.dashboard';

    /**
     * Today's [start, end] for a dashboard "today" panel, judged in the STORE's
     * own timezone. A store in New York on a Qatar-timezone platform must see its
     * own calendar day, not the platform's.
     *
     * created_at is persisted as an app-timezone wall-clock, so the store-local
     * day boundaries are converted back to the app timezone before they are used
     * as query bounds. A null $storeId (fleet / all-stores view) resolves to the
     * platform timezone, making this a no-op and byte-identical to the old
     * Carbon::now()->startOfDay()/endOfDay().
     *
     * @return array{Carbon, Carbon} the store-local day's [start, end], each in the app timezone
     */
    private static function todayWindow(?int $storeId): array
    {
        $appTz = config('app.timezone') ?: 'UTC';
        $tz = $storeId ? store_tz($storeId) : $appTz;
        $now = Carbon::now($tz);

        return [
            $now->copy()->startOfDay()->setTimezone($appTz),
            $now->copy()->endOfDay()->setTimezone($appTz),
        ];
    }

    public function __construct()
    {
        $this->activeTheme = active_theme();
    }

    /**
     * Sidebar store/branch switcher: persist the selection in the session and
     * send the user back to the page they were on, re-scoped to the new store.
     */
    public function switchContext(Request $request)
    {
        $storeId = (int) $request->input('store_id');
        $branchId = (int) $request->input('branch_id');
        $contextAction = $request->input('context_action');

        if (! dashboard_can_access_store($storeId)) {
            return redirect()->back();
        }

        $storeChanged = $storeId !== (int) session('dashboard_store_id');
        session(['dashboard_store_id' => $storeId]);

        $branchValid = $branchId && Branch::where('id', $branchId)->where('store_id', $storeId)->exists();

        if ($branchValid) {
            session(['dashboard_branch_id' => $branchId]);
        } elseif ($contextAction === 'branch') {
            // An empty branch submitted by the branch selector is an explicit
            // "all branches" choice, even when the store was only a saved
            // default and had not been written to the session yet.
            session(['dashboard_branch_id' => 0]);
        } elseif ($storeChanged) {
            // New store: no explicit branch choice yet — saved defaults apply.
            session()->forget('dashboard_branch_id');
        } else {
            // 0 = the user explicitly chose "all branches" (beats any default).
            session(['dashboard_branch_id' => 0]);
        }

        if ($request->boolean('save_default')) {
            $this->saveContextDefault($storeId, $branchValid ? $branchId : 0);
        }

        // P1-3: write-through page selectors post an explicit same-origin redirect_to
        // (the page they live on) so page + sidebar can never disagree. Only honour a
        // local path — never an absolute/off-site URL (open-redirect guard).
        $redirectTo = SafeRedirect::path(is_string($request->input('redirect_to')) ? $request->input('redirect_to') : null);
        if ($redirectTo !== null) {
            return redirect()->to($this->contextRedirectUrl($storeId, $redirectTo));
        }

        return redirect()->to($this->contextRedirectUrl($storeId));
    }

    /**
     * Persist the current selection as the actor's login default. Owners
     * store both ids; staff (single-store, not branch-pinned) store the
     * branch only. Defaults are validated on read, so a later loss of
     * access simply falls back to the first store / all branches.
     */
    private function saveContextDefault(int $storeId, int $branchId): void
    {
        if ($staff = staff_session()) {
            if (! $staff->branch_id) {
                $staff->forceFill(['default_branch_id' => $branchId ?: null])->save();
            }

            return;
        }

        if ($ownerId = dashboard_owner_id()) {
            UserOption::updateUserOption($ownerId, 'default_store_id', $storeId);
            UserOption::updateUserOption($ownerId, 'default_branch_id', $branchId);
        }
    }

    /**
     * Where to land after a switch. If the previous page carried a restaurant
     * in its URL, rebuild that URL for the new store; if it was not
     * store-scoped, just go back (those pages read the session).
     */
    private function contextRedirectUrl(int $storeId, ?string $target = null): string
    {
        $previous = $target ?: url()->previous();
        $fallback = route('dashboard');

        if (! $previous) {
            return $fallback;
        }

        try {
            $route = app('router')->getRoutes()->match(Request::create($previous));
        } catch (\Throwable $e) {
            return $fallback;
        }

        $params = $route->parameterNames();
        $storeParams = array_values(array_intersect($params, ['store', 'storeId']));
        $query = [];
        parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);

        if (empty($storeParams)) {
            if (array_key_exists('store_id', $query)) {
                $query['store_id'] = $storeId;
            }
            if ($route->getName() === 'theme.customizer.page' && array_key_exists('store', $query)) {
                $query['store'] = $storeId;
            }

            // The selected branch already lives in the session. Keeping an old
            // branch_id in the return URL would make dashboard.sync overwrite it.
            unset($query['branch_id']);

            $path = preg_replace('/\?.*$/', '', $previous);

            return $query ? $path.'?'.http_build_query($query) : $path;
        }

        // Nested resources (menus, orders…) can't be remapped to another store.
        if (array_diff($params, $storeParams) || ! $route->getName()) {
            return $fallback;
        }

        try {
            $url = route($route->getName(), [$storeParams[0] => $storeId]);
            unset($query['store_id'], $query['branch_id']);

            return $query ? $url.'?'.http_build_query($query) : $url;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /** Owner dashboard: KPIs, scan and order charts for the selected window. */
    public function index(): View
    {
        $owner = dashboard_owner();
        $dashboardActor = dashboard_actor();
        $isStaffDashboard = is_staff_dashboard();

        // R1: reporting window resolved from the request (Today / 7d / 30d / Month / Custom).
        $window = $this->resolvePeriod();
        $start = $window['start'];
        $end = $window['end'];
        $prevStart = $window['prevStart'];
        $prevEnd = $window['prevEnd'];
        $period = $window['period'];
        $compare = $window['compare'];
        $periodLabel = $window['label'];
        $rangeFrom = $window['from'];
        $rangeTo = $window['to'];

        // KPI data
        $stores = dashboard_stores();
        $total_stores = $stores->count();

        // R2: portfolio + plan usage stay account-wide; activity metrics (scans, orders)
        // follow the sidebar store/branch switcher. ?scope=all restores the owner-wide view.
        // NOTE: store_page_views carry no branch_id, so branch scoping applies to orders only.
        $accessibleIds = dashboard_store_ids();
        $scopeAll = request('scope') === 'all';
        $activeStore = $scopeAll ? null : dashboard_current_store();
        $activeBranch = $scopeAll ? null : dashboard_current_branch();
        $post_ids = $activeStore ? [(int) $activeStore->id] : $accessibleIds;
        $branchId = $activeBranch?->id;

        // R12: per-user dashboard layout — which optional cards are hidden (user_options JSON, no migration).
        $hiddenCards = [];
        if ($owner) {
            $raw = UserOption::getUserOption($owner->id, 'dashboard_hidden_cards');
            $decoded = $raw ? json_decode($raw, true) : [];
            $hiddenCards = is_array($decoded) ? $decoded : [];
        }

        // P2-2: "needs attention" exceptions (context-scoped). Hideable via customize.
        $attention = in_array('attention', $hiddenCards, true)
            ? []
            : DashboardAttentionService::for($post_ids);

        // P2-4: multi-store fleet landing — on ?scope=all for owners with >1 store,
        // the home becomes a one-row-per-store table. Single-store owners never see it.
        $fleet = [];
        $fleetCurrencyTotals = []; // MC-40: fleet sales per currency (populated below)
        if (! $isStaffDashboard && $scopeAll && count($accessibleIds) > 1) {
            // D3: the fleet strip needs per-store sales sum + order count only —
            // pure SQL aggregates over the snapshotted grand_total column (R1c),
            // never a hydrate-then-PHP-sum of the whole order+item graph.
            // N+1: per-store attention counts in one batch (6 grouped queries) instead
            // of DashboardAttentionService::for() once per store (6 × N).
            $fleetAttention = DashboardAttentionService::countsByStore($accessibleIds);
            foreach (Store::whereIn('id', $accessibleIds)->orderBy('title')->get(['id', 'title', 'slug']) as $store) {
                // Each row is one store — "today" is that store's own calendar day.
                [$todayStart, $todayEnd] = self::todayWindow((int) $store->id);
                $lwStart = $todayStart->copy()->subWeek();
                $lwEnd = $todayEnd->copy()->subWeek();
                $todaySalesStore = Money::round(Order::revenueSum(Order::where('store_id', $store->id)
                    ->whereBetween('created_at', [$todayStart, $todayEnd])->countedToday()), 2);
                // P6-296: today's orders count uses the same "counted today"
                // predicate as today's sales — cancelled-unpaid and
                // pending_approval orders don't count, so the two columns
                // beside each other agree.
                $todayCount = Order::where('store_id', $store->id)
                    ->whereBetween('created_at', [$todayStart, $todayEnd])
                    ->countedToday()->count();
                $lwSales = Money::round(Order::revenueSum(Order::where('store_id', $store->id)
                    ->whereBetween('created_at', [$lwStart, $lwEnd])), 2);
                // P6-296: 'unpaid' is hosted-checkout, not in the kitchen yet,
                // so it leaves the live strip — `pending_approval` is filtered
                // out by Order::scopeLive() (already applied below). is_paid=1
                // also required: unpaid preparing orders are "held, not live".
                $live = Order::where('store_id', $store->id)
                    ->live()
                    ->where('is_paid', 1)
                    ->whereNotIn('status', ['completed', 'cancelled', 'delivered', 'unpaid'])
                    ->count();
                $delta = $lwSales > 0 ? round((($todaySalesStore - $lwSales) / $lwSales) * 100, 1) : null;
                $fleet[] = [
                    'id' => $store->id,
                    'title' => $store->title,
                    'sales' => $todaySalesStore,
                    'orders' => $todayCount,
                    'delta' => $delta,
                    'live' => $live,
                    'attention' => $fleetAttention[$store->id] ?? 0,
                ];
            }

            // MC-40: fleet-wide sales split by currency, so a multi-currency fleet
            // is never blind-summed into one meaningless figure. Single-currency
            // fleets get one entry and the view renders exactly as before.
            // A mixed-timezone fleet has no single "today"; this cross-store roll-up
            // stays on the platform day (per-store rows above use each store's tz).
            [$fleetTodayStart, $fleetTodayEnd] = self::todayWindow(null);
            $fleetCurrencyTotals = Order::revenueSumByCurrency(
                Order::whereIn('store_id', $accessibleIds)->whereBetween('created_at', [$fleetTodayStart, $fleetTodayEnd])
            );
        }

        // P2-6: peer-benchmark "vs similar stores" — only for opted-in owners on a
        // single store whose cohort has a published aggregate. Flag-gated OFF.
        // P1-13 13h: peer benchmarks (cross-business) are ee — the Analytics ee module answers the slot with the
        // cohort row (same query); Community has none, so the card never shows.
        $peerBenchmark = null;
        $aiAssistant = self::aiAssistantAvailable($owner);
        if (! $isStaffDashboard && $aiAssistant && Hooks::has(self::PEER_BENCHMARK_SLOT)
            && $activeStore && peer_benchmark_opted_in($owner?->id)) {
            $o30 = Order::where('store_id', $activeStore->id)
                ->where('created_at', '>=', Carbon::now()->subDays(30))->count();
            // MC-43: match the cohort row for THIS store's currency, so an OMR store
            // is benchmarked against OMR peers, not a currency-blended median.
            $peerBenchmark = Hooks::first(self::PEER_BENCHMARK_SLOT, peer_benchmark_cohort($o30), strtoupper(store_currency($activeStore->id)));
        }

        // Plan usage + menu totals are account-wide (never store-scoped).
        $total_items = 0;
        $total_scan = 0;
        if (! empty($accessibleIds)) {
            $total_items = Menu::whereIn('store_id', $accessibleIds)->count();
            $total_scan = StoreView::whereIn('store_id', $accessibleIds)->count();
        }

        // R1/#53 + R3 · P1-7: the current window is still hydrated with items —
        // the top-items breakdown genuinely needs the line graph — but every
        // pure-revenue figure (prev window, today strip, branch comparison, fleet)
        // is a SQL aggregate over the snapshotted grand_total column.
        // Scan chart: one bucket per day of the selected window, labelled "d M".
        $days = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $days[] = date_formating($day, 'd M');
        }
        $lastMonthScans = 0;
        $periodAgg = null;
        $lastMonthOrders = 0;   // D3: previous window is now an aggregate, default 0
        $prevRevenue = 0.0;
        $prevRevenueCount = 0;  // R3: revenue-counted order count for the prev AOV denominator
        $revenueGap = 0;        // H10: revenue-counting orders with no snapshot yet
        if (! empty($post_ids)) {
            $lastMonthScans = StoreView::whereIn('store_id', $post_ids)
                ->whereBetween('date', [$prevStart, $prevEnd])->count();

            $orderScope = fn () => Order::whereIn('store_id', $post_ids)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

            // Current window is hydrated — its items feed the top-items + by-day
            // breakdowns below. The previous window is used ONLY for count +
            // revenue, so it is a pure SQL aggregate over the snapshotted
            // grand_total column (R1c/D3) — no order/item hydration.
            $periodAgg = DashboardPeriodAggregates::compute($post_ids, $branchId, $start, $end, $days, $activeStore ? store_currency($activeStore->id) : null);
            $prevScope = fn () => Order::whereIn('store_id', $post_ids)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereBetween('created_at', [$prevStart, $prevEnd]);
            $lastMonthOrders = $prevScope()->count();
            // The comparison window is restricted to the SAME currency as the current window's figure, so the
            // trend % never compares (say) OMR against USD.
            $prevCurScope = fn () => DashboardPeriodAggregates::inCurrency($prevScope(), $periodAgg['currency'], $post_ids);
            $prevRevenue = Money::round(Order::revenueSum($prevCurScope()), 2);
            $prevRevenueCount = $prevCurScope()->revenueCounted()->count();

            // H10: revenueSum() sums the snapshot column, so an order that predates
            // the R1c migration and has never been backfilled contributes 0. Count
            // them across both windows so the view can say so instead of showing a
            // quietly-low number.
            $revenueGap = Order::unsnapshottedCount($prevScope())
                + Order::unsnapshottedCount($orderScope()->whereBetween('created_at', [$start, $end]));
        }

        // P6-62: current-window figures are SQL aggregates (integer minor units, one currency per figure).
        $thisMonthOrders = $periodAgg['orderCount'] ?? 0;
        $salesRevenue = $periodAgg['salesRevenue'] ?? 0.0;
        $revenueOrderCount = $periodAgg['revenueOrderCount'] ?? 0;
        // Display currency of the revenue figures + a store to format with; the other currencies are listed, never summed.
        $moneyCurrency = $periodAgg['currency'] ?? null;
        $moneyStoreId = null;
        foreach ($moneyCurrency ? $post_ids : [] as $sid) {
            if (strtoupper(store_currency((int) $sid)) === $moneyCurrency) {
                $moneyStoreId = (int) $sid;
                break;
            }
        }
        $otherCurrencies = [];
        foreach (($periodAgg['revenueMinorByCurrency'] ?? []) as $code => $minor) {
            if ($code !== $moneyCurrency) {
                $otherCurrencies[$code] = Money::majorFromMinor($minor, $code);
            }
        }
        $moneyCurrencyBasis = $periodAgg['currencyBasis'] ?? 'store';
        $avgOrderValue = $revenueOrderCount ? Money::round($salesRevenue / $revenueOrderCount, 2) : 0;
        $prevAov = $prevRevenueCount ? Money::round($prevRevenue / $prevRevenueCount, 2) : 0;

        // R5: "today / live" ops strip — always today/now, independent of the selected period.
        $todaySales = 0.0;
        $todayOrderCount = 0;
        $liveTickets = 0;
        $avgPrepMin = null;
        if (! empty($post_ids)) {
            $liveScope = fn () => Order::whereIn('store_id', $post_ids)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

            // "Today" for a single store is its own calendar day; a fleet view
            // ($activeStore null) stays on the platform day.
            [$liveTodayStart, $liveTodayEnd] = self::todayWindow($activeStore?->id);
            // P1-7: today's count + sales are pure SQL aggregates over the
            // snapshotted grand_total (R1c) — no order/item hydration. The rare
            // legacy row with no snapshot yet (pre orders:snapshot-totals /
            // orders:backfill-totals run) falls back to the live accessor so
            // today's number never silently under-reports; that fallback set is
            // empty once the backfill has run.
            $todayScope = fn () => $liveScope()->whereBetween('created_at', [$liveTodayStart, $liveTodayEnd]);
            // P6-296: same "counted today" predicate as today's sales (P5-251
            // companion), so the two columns always agree.
            $todayOrderCount = $todayScope()->countedToday()->count();
            $todaySales = Order::revenueSum($todayScope()->countedToday());
            $todayGapOrders = $todayScope()->countedToday()->whereNull('grand_total_minor')
                ->with(['items.menu', 'items.itemExtras.extra', 'items.variant'])->get();
            $todaySales = Money::round($todaySales + $todayGapOrders->sum(fn ($o) => (float) $o->total), 2);

            // P6-296: Live tickets = orders actually open in the kitchen.
            // scopeLive() drops `pending_approval` (waiting for staff approve)
            // and scheduled-but-unreleased rows. 'unpaid' is hosted-checkout,
            // not yet at the kitchen. Terminal statuses still filtered out
            // locally for the belt-and-braces match with the fleet strip above.
            // P6-296: is_paid=1 too — an unpaid preparing order is "held, not in
            // the kitchen" (no money, kitchen has nothing to cook).
            $liveTickets = $liveScope()
                ->live()
                ->where('is_paid', 1)
                ->whereNull('completed_at')->whereNull('cancelled_at')
                ->whereNotIn('status', ['completed', 'cancelled', 'delivered', 'unpaid'])
                ->count();

            // Average prep minutes from today's orders that have both kitchen
            // timestamps — needs only the two columns, never the item graph.
            $prep = $todayScope()
                ->whereNotNull('preparing_at')->whereNotNull('ready_at')
                ->get(['id', 'preparing_at', 'ready_at'])
                ->map(fn ($o) => Carbon::parse($o->preparing_at)->diffInMinutes(Carbon::parse($o->ready_at)));
            $avgPrepMin = $prep->count() ? (int) round($prep->avg()) : null;
        }

        // trend helper: pct change relative to last period
        $trend = fn ($now, $last) => $last > 0 ? round((($now - $last) / $last) * 100) : ($now > 0 ? 100 : 0);

        // Earnings
        $total_earnings = 0;
        if (! $isStaffDashboard && $owner && class_exists(Transaction::class)) {
            $total_earnings = Transaction::where('user_id', $owner->id)
                ->where('status', 'success')
                ->sum(DB::raw(Transaction::minorMoneySql('amount')));
        }

        // Recent transactions
        $transactions = collect();
        if (! $isStaffDashboard && $owner && class_exists(Transaction::class)) {
            $transactions = Transaction::where('user_id', $owner->id)
                // BL-039 / BL-038: a kept subscription pending/set-up row, a settled MoR checkout and an abandoned one are not payments.
                ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', Transaction::CHECKOUT_BOOKKEEPING))
                ->latest()
                ->limit(5)
                ->get();
        }

        // Plan info
        $plan = $owner?->plan();
        $user_plan_name = is_string($plan) ? $plan : ($plan->name ?? ___('Free'));

        $start_date = $expiry_date = '-';
        $pay_mode = 'one_time';
        $interval = null;

        if ($owner && $upgrade = $owner->upgrade) {
            $start_date = date_formating($upgrade->upgrade_lasttime);
            $expiry_date = date_formating($upgrade->upgrade_expires);
            $pay_mode = $upgrade->pay_mode;
            $interval = $upgrade->interval;
        }

        $scans = array_fill_keys($days, 0);

        $scansByDevice = [];
        $scansByHour = array_fill(0, 24, 0);
        $topLanguages = [];
        $repeatVisitors = 0;
        $uniqueVisitors = 0;

        if (! empty($post_ids)) {
            $scansPerDay = StoreView::query()
                ->whereIn('store_id', $post_ids)
                ->whereBetween('date', [$start, $end])
                ->selectRaw('DATE(date) as scan_day, COUNT(1) as total')
                ->groupBy('scan_day')
                ->pluck('total', 'scan_day');

            foreach ($scansPerDay as $scanDay => $total) {
                $scans[date_formating($scanDay, 'd M')] = $total;
            }

            // Scans by Device
            $deviceData = StoreView::whereBetween('date', [$start, $end])
                ->whereIn('store_id', $post_ids)
                ->selectRaw('device, COUNT(1) as count')
                ->groupBy('device')
                ->pluck('count', 'device')->toArray();

            $scansByDevice = [
                'Desktop' => $deviceData['Desktop'] ?? 0,
                'Mobile' => $deviceData['Mobile'] ?? 0,
                'Tablet' => $deviceData['Tablet'] ?? 0,
            ];

            // Scans by Hour
            $hourExpr = Sql::hour('date');

            $hourData = StoreView::whereBetween('date', [$start, $end])
                ->whereIn('store_id', $post_ids)
                ->selectRaw("{$hourExpr} as hr, COUNT(1) as count")
                ->groupBy('hr')
                ->pluck('count', 'hr')->toArray();

            foreach ($hourData as $hr => $count) {
                // SQLite strftime('%H') returns a zero-padded string ('09'); cast so it
                // lands in the correct 0–23 int bucket (MySQL HOUR() already returns int).
                $scansByHour[(int) $hr] = $count;
            }

            // Top Languages
            $topLanguages = StoreView::whereBetween('date', [$start, $end])
                ->whereIn('store_id', $post_ids)
                ->selectRaw('lang, COUNT(1) as count')
                ->whereNotNull('lang')
                ->groupBy('lang')
                ->orderByDesc('count')
                ->limit(5)
                ->get();

            // Repeat Visitors (by IP)
            $ipCounts = StoreView::whereBetween('date', [$start, $end])
                ->whereIn('store_id', $post_ids)
                ->selectRaw('ip, COUNT(1) as count')
                ->groupBy('ip')
                ->get();

            $uniqueVisitors = $ipCounts->where('count', 1)->count();
            $repeatVisitors = $ipCounts->where('count', '>', 1)->count();
        }
        $scans = array_values($scans);

        // R3: per-day sales & orders series for KPI sparklines, aligned to the chart buckets.
        $ordersSeries = array_values($periodAgg['ordersByDay'] ?? array_fill_keys($days, 0));
        $salesSeries = array_values($periodAgg['salesByDay'] ?? array_fill_keys($days, 0.0));
        // R7: top-selling items (revenue-counted orders; see DashboardPeriodAggregates for the revenue definition).
        $topItems = $periodAgg['topItems'] ?? [];

        // R12: the multi-branch comparison card is a chain tool (ee, owner 2026-10-05): the Analytics ee half answers this
        // slot with the card's view + rows; Community (no licence) has no card.
        $branchCard = $activeStore ? Hooks::answer(self::BRANCH_CARD_SLOT, (int) $activeStore->id, $start, $end) : null;

        // KPI Goals (#52)
        $scan_goal = $owner ? UserSettings::legacy((int) $owner->id, 'scan_goal_month') : null;
        $scan_goal = $scan_goal ? (int) $scan_goal : null;

        // #72: Usage meters — effective limits (includes add-on boosts from #67)
        $usageLimits = [
            'store' => ['used' => $total_stores, 'limit' => $owner ? effective_limit('restaurant', $owner) : $total_stores],
            'scan' => ['used' => $total_scan,        'limit' => $owner ? effective_limit('scan', $owner) : $total_scan],
            'menu' => ['used' => $total_items,       'limit' => $owner ? effective_limit('menu', $owner) : $total_items],
        ];

        // #53 + R3: Trend data for dashboard KPIs
        $trends = [
            'scans' => $trend(array_sum($scans), $lastMonthScans),
            'orders' => $trend($thisMonthOrders, $lastMonthOrders),
            'sales' => $trend($salesRevenue, $prevRevenue),
            'aov' => $trend($avgOrderValue, $prevAov),
        ];

        // Abuse Guard P7 (7.7): the merchant's own resource usage (30d) — doubles as an upsell surface.
        $abuseUsage = $owner ? app(UsageReport::class)->forTenant((int) $owner->id, ['requests_public', 'orders', 'orders_failed', 'ai_credits', 'storage_mb'], 30) : [];

        return view('owner.dashboard.index', compact(
            'abuseUsage',
            'days', 'scans', 'start_date', 'expiry_date',
            'total_stores', 'total_items', 'total_scan', 'total_earnings',
            'stores', 'transactions', 'user_plan_name',
            'scansByDevice', 'scansByHour', 'topLanguages', 'repeatVisitors', 'uniqueVisitors',
            'scan_goal', 'usageLimits', 'trends', 'thisMonthOrders', 'lastMonthOrders',
            'dashboardActor', 'isStaffDashboard',
            'period', 'compare', 'periodLabel', 'rangeFrom', 'rangeTo',
            'activeStore', 'activeBranch', 'scopeAll',
            'salesRevenue', 'avgOrderValue', 'salesSeries', 'ordersSeries', 'revenueGap',
            'todaySales', 'todayOrderCount', 'liveTickets', 'avgPrepMin', 'topItems',
            'moneyCurrency', 'moneyStoreId', 'otherCurrencies', 'moneyCurrencyBasis',
            'hiddenCards', 'branchCard', 'attention', 'fleet', 'fleetCurrencyTotals', 'peerBenchmark', 'aiAssistant'
        ));
    }

    /**
     * R12: persist which optional dashboard cards the owner wants visible.
     * Stored as a JSON list of HIDDEN card keys in user_options (no migration).
     */
    public function saveLayout(Request $request)
    {
        $owner = dashboard_owner();
        if (! $owner) {
            return back();
        }
        $allowed = ['livestrip', 'trend', 'breakdowns', 'topitems', 'attention'];
        $visible = array_intersect($allowed, (array) $request->input('cards', []));
        $hidden = array_values(array_diff($allowed, $visible));
        UserOption::updateUserOption($owner->id, 'dashboard_hidden_cards', json_encode($hidden));

        // P1-8: optional widget ORDER (a list of card keys, first = top). Persisted
        // separately so show/hide and ordering don't clobber each other.
        if ($request->has('order')) {
            $order = array_values(array_intersect((array) $request->input('order', []), $allowed));
            UserOption::updateUserOption($owner->id, 'dashboard_card_order', json_encode($order));
        }

        if ($request->expectsJson() || $request->boolean('_ajax')) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', ___('Dashboard layout saved.'));
    }

    /**
     * FIX-2 F2-12 (gap audit): is the ai_assistant SKU actually available to this
     * owner? Kill-switch is the veto (unchanged); once the key is `plan_gated` the
     * owner must also be entitled — plan row, add-on, or override.
     *
     * This is the ONE reader for the assistant's three surfaces: the dashboard
     * drawer's render flag, the peer-benchmark card, and the drawer's XHR
     * endpoint. `feature:ai_assistant` middleware guards the routes; this keeps the
     * rendered UI honest so the drawer is never advertised into a 403.
     */
    public static function aiAssistantAvailable(?User $owner = null): bool
    {
        try {
            $owner = $owner ?: (function_exists('dashboard_owner') ? dashboard_owner() : auth()->user());

            return FeatureAccess::allows(
                $owner instanceof User ? $owner : null,
                'ai_assistant'
            );
        } catch (\Throwable $e) {
            return false; // fail closed
        }
    }

    /**
     * P2-6: grounded dashboard assistant. Feature-flagged OFF by default
     * (admin_allow_ai_assistant). Tenant-scoped to the context store; the answer
     * is grounded in real, scoped numbers (and cites them). Falls back to a
     * deterministic grounded reply when no AI provider is configured.
     *
     * FIX-2 F2-12: `feature:ai_assistant` on the route is the primary gate; the
     * check below is defence-in-depth for any future caller that reaches the
     * action without it (403, not the old 404 — the key is sold, not secret).
     */
    public function assistant(Request $request)
    {
        abort_unless(self::aiAssistantAvailable(), 403);

        $message = trim((string) $request->input('message', ''));
        $store = dashboard_current_store();
        $scopeIds = $store ? [(int) $store->id] : dashboard_store_ids();

        // Grounded, scoped facts the answer must cite — "today" in the store's
        // own timezone (platform day for the all-stores view).
        [$todayStart, $todayEnd] = self::todayWindow($store?->id);
        $orders = Order::whereIn('store_id', $scopeIds)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->with(['items.menu', 'items.itemExtras.extra', 'items.variant'])->get();
        $facts = [
            'store' => $store->title ?? __('All stores'),
            'today_orders' => $orders->count(),
            'today_sales' => Money::round($orders->filter(fn ($o) => $o->countsAsRevenue())
                ->sum(fn ($o) => (float) $o->total), 2),   // H10: match the strip above
        ];

        $answer = null;
        try {
            $factory = app(AiProviderChain::class);
            $provider = collect($factory->chain($store?->id))->first(fn ($p) => $p && $p->isConfigured());
            if ($provider) {
                $sys = 'You are a restaurant dashboard assistant. Answer ONLY from these scoped numbers and cite them: '.json_encode($facts).'. If asked about anything outside them, say you can only report the dashboard figures.';
                $res = $provider->chat([
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $message ?: 'Summarise today.'],
                ], ['max_tokens' => 300]);
                $answer = is_object($res) ? ($res->text ?? null) : null;
            }
        } catch (\Throwable $e) {
            $answer = null;
        }

        if (! $answer) {
            // Deterministic grounded fallback (no provider configured / call failed).
            $answer = __(':store today: :orders orders, :sales in sales.', [
                'store' => $facts['store'],
                'orders' => number_format($facts['today_orders']),
                'sales' => price_symbol_format($facts['today_sales']),
            ]);
        }

        return response()->json(['answer' => $answer, 'cited' => $facts]);
    }

    /**
     * P2-3: reports hub — every report destination grouped into categories, each
     * card landing on a store-scoped, periodbar-driven page. Gated by route
     * existence so a plan without a feature simply drops that card.
     */
    public function reports()
    {
        $categories = juwri_report_catalog();

        return view('owner.reports.index', compact('categories'));
    }

    /**
     * P2-3: toggle a pinned favourite (a report/nav destination key) in
     * user_options `nav_pins` (max 6). Keys are the report catalog / nav route
     * names, validated on read, so a stale pin simply disappears.
     */
    public function togglePin(Request $request)
    {
        $owner = dashboard_owner();
        if (! $owner) {
            return response()->json(['ok' => false], 403);
        }

        $key = (string) $request->input('key');
        if ($key === '') {
            return response()->json(['ok' => false], 422);
        }

        $pins = juwri_nav_pin_keys();
        if (in_array($key, $pins, true)) {
            $pins = array_values(array_diff($pins, [$key]));
        } elseif (count($pins) < 6) {
            $pins[] = $key;
        }
        UserOption::updateUserOption($owner->id, 'nav_pins', json_encode(array_values($pins)));

        return response()->json(['ok' => true, 'pinned' => in_array($key, $pins, true), 'pins' => $pins]);
    }

    /**
     * P2-1/P2-2: command palette v2 — grouped, relevance-ranked search over nav
     * pages + a docs group + tenant entities (orders, menu items, customers).
     * Every entity query stays restricted to the owner's accessible stores
     * (context store first), so cross-tenant rows can never surface — a test
     * asserts that. LIMIT 5 per group; pages come from the shared nav
     * inventory, docs from the users/ help-center corpus (DocsCorpus, the same
     * engine behind the dev-docs MCP search).
     *
     * Every group now RANKS by relevance instead of DB/insertion order (see
     * relevanceScore()/rankByRelevance() below) — the same scoring SHAPE
     * DocsCorpus::search() already uses (hitTerms*100 + score, so covering
     * more DISTINCT query terms always outranks repeating a single term). A
     * levenshtein() fallback (precedent: OvertureIngestService::
     * namesAreSimilar(), OvertureIngestService.php:149) covers typos, gated
     * behind the exact pass finding nothing in that group so the common
     * keystroke-by-keystroke path never pays the fuzzy cost.
     */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        // WA-E3 precedent (WhatsAppHelper::whatsapp_latin_digits): Arabic-Indic
        // and Persian numerals (٠-٩ / ۰-۹) are a normal way to type an order id
        // or phone number here. Normalize BEFORE the ctype_digit() id branch and
        // the phone/customer LIKE matching below — otherwise an Arabic-Indic
        // digit query silently matches nothing against Latin-stored data.
        $q = whatsapp_latin_digits($q);
        // P6-101: cap the length once, before it reaches any SQL or the fuzzy ranker.
        $q = trim(mb_substr($q, 0, SearchTerm::MAX));
        $terms = self::tokenize($q);
        $store = dashboard_current_store();
        $scopeIds = $store ? [(int) $store->id] : dashboard_store_ids();

        // Pages: reuse the single nav inventory (label+group match), ranked.
        $pages = [];
        if ($q !== '') {
            $candidates = array_map(fn ($item) => [
                'label' => $item['label'], 'url' => $item['url'], 'group' => $item['group'] ?? '',
            ], dashboard_nav_search());
            $pages = self::rankByRelevance($candidates, $terms, 5, fn ($c) => $c['label'].' '.$c['group'], $q);
        }

        $orders = $items = $customers = [];
        if ($q !== '' && ! empty($scopeIds)) {
            $orderRows = Order::whereIn('store_id', $scopeIds)
                ->where(function ($w) use ($q) {
                    SearchTerm::apply($w, ['customer_name'], $q, ['phone_number']);
                    if (ctype_digit($q)) {
                        $w->orWhere('id', (int) $q);
                    }
                })
                ->limit(50)->get(['id', 'store_id', 'customer_name']);
            if ($orderRows->isEmpty() && $terms !== []) {
                // Typo fallback gate: the literal query matched nothing via SQL
                // LIKE — widen to a small recent pool (still tenant-scoped) so
                // rankByRelevance()'s own fuzzy pass has candidates to compare.
                $orderRows = Order::whereIn('store_id', $scopeIds)->latest('id')->limit(30)
                    ->get(['id', 'store_id', 'customer_name']);
            }
            $orderItems = $orderRows->map(fn ($o) => [
                'label' => '#'.$o->id.($o->customer_name ? ' · '.$o->customer_name : ''),
                // D8: carry the order id so the palette lands on the specific
                // order (D2 consumes ?order= to scroll-to + highlight it).
                'url' => \Route::has('stores.orders') ? route('stores.orders', $o->store_id).'?order='.$o->id : '#',
            ])->all();
            $orders = self::rankByRelevance($orderItems, $terms, 5, fn ($c) => $c['label'], $q);

            // P1-17: Scout database search (item name; LIKE, plus full-text on MySQL/PostgreSQL), these stores only.
            $itemRows = Menu::searchInStores($q, $scopeIds)
                ->query(fn ($b) => $b->select(['items.id', 'items.store_id', 'items.name']))
                ->take(50)->get();
            if ($itemRows->isEmpty() && $terms !== []) {
                $itemRows = Menu::whereIn('store_id', $scopeIds)->latest('id')->limit(30)
                    ->get(['id', 'store_id', 'name']);
            }
            $menuItems = $itemRows->map(fn ($m) => [
                'label' => $m->name,
                // D8: carry the item id so the menu page focuses that item (?item=).
                'url' => \Route::has('stores.menu') ? route('stores.menu', $m->store_id).'?item='.$m->id : '#',
            ])->all();
            $items = self::rankByRelevance($menuItems, $terms, 5, fn ($c) => $c['label'], $q);

            // P1-17: Scout database search (name/phone/email), these stores only.
            $customerRows = Customer::searchInStores($q, $scopeIds)
                ->query(fn ($b) => $b->select(['customers.id', 'customers.name', 'customers.phone']))
                ->take(50)->get();
            if ($customerRows->isEmpty() && $terms !== []) {
                $customerRows = Customer::whereIn('store_id', $scopeIds)->latest('id')->limit(30)
                    ->get(['id', 'name', 'phone']);
            }
            $customerItems = $customerRows->map(fn ($c) => [
                'label' => $c->name ?: $c->phone,
                'url' => \Route::has('customers.show') ? route('customers.show', $c->id) : '#',
            ])->all();
            $customers = self::rankByRelevance($customerItems, $terms, 5, fn ($c) => $c['label'], $q);
        }

        // Action verbs — gated on the same route existence as nav items.
        $actions = [];
        if ($q !== '') {
            $verbs = [
                ['stores.create', ___('Create a store')],
                ['stores.coupons.index', ___('Create a coupon')],
                ['campaigns.index', ___('New campaign')],
            ];
            $needle = mb_strtolower($q);
            foreach ($verbs as [$route, $label]) {
                if (\Route::has($route) && mb_strpos(mb_strtolower($label), $needle) !== false) {
                    $actions[] = ['label' => $label, 'url' => route($route)];
                }
            }
        }

        // Docs: fold in the same users/ help-center corpus that DocsCorpus
        // already indexes for the dev-docs MCP tool, so a "how do I…" query
        // surfaces a guide instead of nothing. Deep-links to help.show, always
        // under the BASE (English) slug — HelpCenterController itself swaps in
        // the Arabic translation for an Arabic viewer (localizedPath()), and
        // DocsCorpus::search() already normalizes an ar/ chunk hit back to that
        // base path, so no locale branching is needed here.
        $docs = [];
        if ($q !== '' && \Route::has('help.show')) {
            foreach (DocsCorpus::search($q, 5, ['users']) as $hit) {
                $slug = preg_replace('#^users/#', '', $hit['doc']);
                if (! preg_match('/^[a-z0-9-]+$/', $slug)) {
                    continue; // defensive: mirrors help.show's own route constraint
                }
                $label = $hit['title'];
                if ($hit['section'] !== '' && $hit['section'] !== '(intro)') {
                    $label .= ' · '.$hit['section'];
                }
                $docs[] = ['label' => $label, 'url' => route('help.show', $slug)];
            }
        }

        return response()->json([
            'query' => $q,
            'groups' => array_values(array_filter([
                $pages ? ['key' => 'pages', 'label' => ___('Pages'), 'items' => $pages] : null,
                $actions ? ['key' => 'actions', 'label' => ___('Actions'), 'items' => $actions] : null,
                $orders ? ['key' => 'orders', 'label' => ___('Orders'), 'items' => $orders] : null,
                $items ? ['key' => 'items', 'label' => ___('Menu items'), 'items' => $items] : null,
                $customers ? ['key' => 'customers', 'label' => ___('Customers'), 'items' => $customers] : null,
                $docs ? ['key' => 'docs', 'label' => ___('Help & docs'), 'items' => $docs] : null,
            ])),
        ]);
    }

    /**
     * Split a query into lowercased search terms for relevanceScore()/
     * fuzzyScore() — same shape as DocsCorpus::search()'s tokenizer (Unicode-
     * aware, drops <2-char noise), except a lone DIGIT is KEPT: an order id
     * like "5" is meaningful search input at one character, unlike a word.
     *
     * @return list<string>
     */
    private static function tokenize(string $q): array
    {
        $raw = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($q)) ?: [];

        return array_values(array_unique(array_filter($raw, fn ($t) => $t !== '' && (mb_strlen($t) >= 2 || ctype_digit($t)))));
    }

    /**
     * Relevance score for one candidate label against tokenized query $terms.
     * Same SHAPE as DocsCorpus::search(): hitTerms*100 dominates so a hit on
     * every distinct term always outranks many hits on a single term. Per
     * term: an exact substring hit scores 3, plus 2 more when it starts the
     * label or starts a WORD inside it (prefix/word-boundary bonus — "ord"
     * matching "Orders" should outrank matching mid-word in "coordinator").
     * An exact whole-label-equals-whole-query match adds a final tiebreaker
     * bonus, so "Pizza" outranks "Deluxe Pizza Feast" for the query "pizza".
     */
    /** @param list<string> $terms */
    private static function relevanceScore(string $label, array $terms): int
    {
        if ($terms === [] || $label === '') {
            return 0;
        }
        $hay = mb_strtolower($label);
        $score = 0;
        $hitTerms = 0;
        foreach ($terms as $term) {
            if (mb_strpos($hay, $term) === false) {
                continue;
            }
            $hit = 3; // exact substring
            if (preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($term, '/').'/u', $hay)) {
                $hit += 2; // prefix / word-boundary bonus
            }
            $hitTerms++;
            $score += $hit;
        }
        if ($hitTerms === 0) {
            return 0;
        }
        if ($hitTerms === count($terms)) {
            $score += 5; // every distinct term present, somewhere
        }
        if ($hay === trim(implode(' ', $terms))) {
            $score += 10; // the whole label IS the whole query
        }

        return $hitTerms * 100 + $score;
    }

    /**
     * Typo-tolerant fallback (precedent: OvertureIngestService::
     * namesAreSimilar(), OvertureIngestService.php:149) — only ever called
     * once relevanceScore() found ZERO hits across an entire group, so a real
     * substring hit never pays this O(terms × words) cost. Matches each term
     * against every WORD in the label within a 15%-of-length edit-distance
     * tolerance (same ratio as the precedent), so "itms" still finds "items".
     */
    /** @param list<string> $terms */
    private static function fuzzyScore(string $label, array $terms): int
    {
        if ($terms === [] || $label === '') {
            return 0;
        }
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($label)) ?: []));
        if ($words === []) {
            return 0;
        }

        $score = 0;
        $hitTerms = 0;
        foreach ($terms as $term) {
            $best = null;
            foreach ($words as $word) {
                $maxLen = max(mb_strlen($term), mb_strlen($word));
                if ($maxLen < 3) {
                    continue; // too short for edit-distance to mean anything
                }
                $dist = levenshtein($term, $word);
                $threshold = max(1, (int) floor($maxLen * 0.15));
                if ($dist <= $threshold && ($best === null || $dist < $best)) {
                    $best = $dist;
                }
            }
            if ($best !== null) {
                $hitTerms++;
                $score += max(1, 3 - $best);
            }
        }

        return $hitTerms > 0 ? $hitTerms * 100 + $score : 0;
    }

    /**
     * Score, rank and cap one group's candidates. Falls back to fuzzyScore()
     * only when the exact pass matched NOTHING at all in this group. $labelOf
     * pulls the matchable text out of each candidate; $rawNeedle is the raw
     * (lowercased) query, used only when $terms is empty (a query too short
     * to tokenize, e.g. one letter) so a short query still narrows an
     * in-memory list like `pages` instead of showing whatever happened to be
     * first — DB-backed groups (orders/items/customers) are already
     * pre-filtered by SQL LIKE at that point, so this branch is a no-op there.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private static function rankByRelevance(array $candidates, array $terms, int $limit, \Closure $labelOf, string $rawNeedle = ''): array
    {
        if ($candidates === []) {
            return [];
        }
        if ($terms === []) {
            $needle = mb_strtolower(trim($rawNeedle));
            if ($needle === '') {
                return array_slice($candidates, 0, $limit);
            }

            return array_slice(array_values(array_filter(
                $candidates,
                fn ($c) => mb_strpos(mb_strtolower($labelOf($c)), $needle) !== false
            )), 0, $limit);
        }

        $scored = [];
        foreach ($candidates as $c) {
            $s = self::relevanceScore($labelOf($c), $terms);
            if ($s > 0) {
                $scored[] = [$c, $s];
            }
        }
        if ($scored === []) {
            foreach ($candidates as $c) {
                $s = self::fuzzyScore($labelOf($c), $terms);
                if ($s > 0) {
                    $scored[] = [$c, $s];
                }
            }
        }

        usort($scored, fn ($a, $b) => $b[1] <=> $a[1]);

        return array_map(fn ($s) => $s[0], array_slice($scored, 0, $limit));
    }

    /**
     * P1-8: notification bell counts — pending reviews, open disputes, waiting
     * guests, and recent failed webhook deliveries. Scoped to the sidebar store
     * (reviews/disputes/waitlist) or owner-wide (webhook endpoints are per-user).
     * Cached 60s per owner+scope to keep the poll cheap (no N+1).
     */
    public function notificationCounts(): JsonResponse
    {
        $ownerId = dashboard_owner_id();
        $store = dashboard_current_store();
        $scopeIds = $store ? [(int) $store->id] : dashboard_store_ids();
        $prefs = dashnotif_prefs($ownerId);

        // M3: the 30s cache key carries a prefs fingerprint, so a prefs edit takes
        // effect on the next poll instead of surviving up to 30s of stale counts.
        $key = 'dashnotif:'.($ownerId ?? 'x').':'.implode(',', $scopeIds).':'.md5((string) json_encode($prefs));
        $key = TenantCache::scopedKey($key); // P1-04 slice 7: counts come from the tenant scope

        $data = Cache::remember($key, 30, fn () => $this->computeNotificationCounts($scopeIds, $ownerId, $prefs));

        // D11-3: a per-user read watermark — the badge lights only when the total
        // rises above what the owner has already seen (computed outside the cache
        // so it reflects the latest watermark).
        $seen = $ownerId ? (int) UserOption::getUserOption($ownerId, 'dashnotif_seen', 0) : 0;
        $data['badge'] = (int) $data['total'] > $seen;

        return response()->json($data);
    }

    /** D11-3: stamp the notification read watermark to the current total. */
    public function markNotificationsSeen(): JsonResponse
    {
        $ownerId = dashboard_owner_id();
        $store = dashboard_current_store();
        $scopeIds = $store ? [(int) $store->id] : dashboard_store_ids();

        if ($ownerId) {
            // M3: the SAME prefs-aware compute as notificationCounts (no duplicated
            // query block), so the watermark can never drift from the shown total.
            $total = $this->computeNotificationCounts($scopeIds, $ownerId, dashnotif_prefs($ownerId))['total'];
            UserOption::updateUserOption($ownerId, 'dashnotif_seen', $total);
        }

        return response()->json(['success' => true]);
    }

    /**
     * M3: the single source of truth for the five bell counts — used by both
     * notificationCounts() (cached) and markNotificationsSeen() (watermark), so
     * they can never drift. Per-event store scope is narrowed by the user's
     * dashnotif_prefs; the orders count additionally drops muted branches
     * (orders.branch_id — the only branch-scoped event; others are store-level).
     *
     * @param  list<int>  $scopeIds
     * @param  array<string,mixed>  $prefs
     * @return array{orders:int,reviews:int,disputes:int,waitlist:int,webhooks:int,total:int}
     */
    private function computeNotificationCounts(array $scopeIds, ?int $ownerId, array $prefs = []): array
    {
        $storesFor = fn (string $event) => array_values(array_filter(
            $scopeIds,
            fn ($sid) => dashnotif_store_enabled($prefs, (int) $sid, null, $event)
        ));

        // New orders awaiting action (placed, not yet accepted by the kitchen).
        // P6-296: include pending_approval — those orders are placed, the
        // diner has paid, and they are NOT in any kitchen queue (scopeLive
        // excludes them). Without this line the bell would stay silent while
        // an order sits in approval limbo.
        $orderStores = $storesFor('orders');
        $orders = 0;
        if ($orderStores !== []) {
            $q = Order::whereIn('store_id', $orderStores)
                ->whereIn('status', ['pending', 'new', Order::STATUS_PENDING_APPROVAL])
                ->whereNull('held_at')
                ->where('created_at', '>=', Carbon::now()->subDay());

            // Drop orders whose branch is explicitly muted. A NULL branch_id (no
            // branch) is always counted; only branches turned off in prefs are cut.
            $mutedBranchIds = $this->mutedBranchIds($prefs, $orderStores);
            if ($mutedBranchIds !== []) {
                $q->where(function ($w) use ($mutedBranchIds) {
                    $w->whereNull('branch_id')->orWhereNotIn('branch_id', $mutedBranchIds);
                });
            }
            $orders = $q->count();
        }

        $reviewStores = $storesFor('reviews');
        $reviews = $reviewStores !== []
            ? MenuReview::whereIn('store_id', $reviewStores)->where('status', 'pending')->count()
            : 0;

        $disputeStores = $storesFor('disputes');
        $disputes = $disputeStores !== []
            ? Dispute::whereIn('store_id', $disputeStores)
                ->whereNotIn('status', ['won', 'lost', 'warning_closed', 'charge_refunded'])->count()
            : 0;

        $waitStores = $storesFor('waitlist');
        $waitlist = $waitStores !== []
            ? WaitlistEntry::whereIn('store_id', $waitStores)->where('status', 'waiting')->count()
            : 0;

        // Webhooks are endpoint-scoped (owner-level, not per-store). Honour the
        // 'webhooks' event toggle in aggregate: suppress only when every scoped
        // store has it off (an owner with no stores keeps them on).
        $webhooks = 0;
        $webhooksOn = $scopeIds === [] || $storesFor('webhooks') !== [];
        if ($ownerId && $webhooksOn) {
            $endpointIds = WebhookEndpoint::where('user_id', $ownerId)->pluck('id');
            if ($endpointIds->isNotEmpty()) {
                $webhooks = WebhookDelivery::whereIn('endpoint_id', $endpointIds)
                    ->where('success', false)
                    ->where('created_at', '>=', Carbon::now()->subDays(7))
                    ->count();
            }
        }

        return [
            'orders' => $orders,
            'reviews' => $reviews,
            'disputes' => $disputes,
            'waitlist' => $waitlist,
            'webhooks' => $webhooks,
            'total' => $orders + $reviews + $disputes + $waitlist + $webhooks,
        ];
    }

    /**
     * M3: branch ids explicitly turned OFF in prefs across the given stores.
     *
     * @param  array<string,mixed>  $prefs
     * @param  list<int>  $storeIds
     * @return list<int>
     */
    private function mutedBranchIds(array $prefs, array $storeIds): array
    {
        $muted = [];
        $stores = $prefs['stores'] ?? [];
        if (! is_array($stores)) {
            return [];
        }

        foreach ($storeIds as $sid) {
            $branches = $stores[(string) $sid]['branches'] ?? ($stores[$sid]['branches'] ?? null);
            if (is_array($branches)) {
                foreach ($branches as $bid => $on) {
                    if ($on === false) {
                        $muted[] = (int) $bid;
                    }
                }
            }
        }

        return $muted;
    }

    /** Notification-sounds settings page: library + the context store's config. */
    public function soundSettings()
    {
        $store = dashboard_current_store();
        abort_unless($store, 404, 'Pick a store first.');

        $branches = Branch::where('store_id', $store->id)->orderBy('id')->get(['id', 'name']);
        // config per layer: 0 = store-level, then each branch's resolved override.
        $configs = ['0' => notification_sounds_config((int) $store->id)];
        foreach ($branches as $b) {
            $configs[(string) $b->id] = notification_sounds_config((int) $store->id, (int) $b->id);
        }

        return view('owner.settings.notification-sounds', [
            'store' => $store,
            'library' => notification_sound_library((int) $store->id),
            'config' => $configs['0'],
            'branches' => $branches,
            'configs' => $configs,
        ]);
    }

    /** Persist the per-store notification-sound config (store_options JSON). */
    public function saveSoundSettings(Request $request)
    {
        $store = dashboard_current_store();
        abort_unless($store, 404, 'Pick a store first.');

        $validIds = collect(notification_sound_library((int) $store->id))->pluck('id')->all();
        $data = $request->validate([
            'branch_id' => 'nullable|integer', // null/empty = store-level layer
            'volume' => 'required|integer|min:0|max:100',
            'events' => 'required|array',
            'events.*.sound' => ['nullable', 'string', function ($attr, $v, $fail) use ($validIds) {
                if ($v !== null && $v !== '' && ! in_array($v, $validIds, true)) {
                    $fail('Unknown sound.');
                }
            }],
            'events.*.repeat' => 'nullable|boolean',
            'events.*.interval' => 'nullable|integer|min:0|max:120', // 0 = n/a (repeat off)
            'quiet' => 'nullable|array',
            'quiet.enabled' => 'nullable|boolean',
            'quiet.from' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'quiet.to' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        // Only known event keys are stored.
        $known = array_keys(notification_sounds_defaults()['events']);
        $events = collect($data['events'])->only($known)->map(fn ($e) => [
            'sound' => (string) ($e['sound'] ?? ''),
            'repeat' => ! empty($e['repeat']),
            'interval' => max(5, min(120, (int) ($e['interval'] ?? 20))),
        ])->all();

        $quiet = [
            'enabled' => ! empty($data['quiet']['enabled']),
            'from' => $data['quiet']['from'] ?? '23:00',
            'to' => $data['quiet']['to'] ?? '07:00',
        ];

        // A branch id must belong to this store; empty = the store-level layer.
        $branchId = ! empty($data['branch_id'])
            && Branch::where('store_id', $store->id)->where('id', $data['branch_id'])->exists()
            ? (int) $data['branch_id'] : null;

        // Patch the relevant layer, preserving the rest of the JSON.
        $stored = StoreSettings::legacy((int) $store->id, 'notification_sounds');
        $json = is_string($stored) ? (array) json_decode($stored, true) : [];
        $layer = ['volume' => (int) $data['volume'], 'quiet' => $quiet, 'events' => $events];

        if ($branchId) {
            $json['branches'] = ($json['branches'] ?? []);
            $json['branches'][$branchId] = $layer;
        } else {
            $json['volume'] = $layer['volume'];
            $json['quiet'] = $layer['quiet'];
            $json['events'] = $layer['events'];
        }
        StoreSettings::set((int) $store->id, 'notification_sounds', json_encode($json));

        return response()->json(['ok' => true, 'config' => notification_sounds_config((int) $store->id, $branchId)]);
    }

    /**
     * M2: per-dashboard-user "Notification preferences" page — which of the
     * owner's stores (and, for orders, which branches) and which of the five
     * bell events light up the dashboard. Absent prefs = everything on, so the
     * initial model is built defaults-on and overlaid with any saved prefs.
     */
    public function notificationPrefs(): View
    {
        $stores = dashboard_stores();
        $prefs = dashnotif_prefs();
        $eventKeys = ['orders', 'reviews', 'disputes', 'waitlist', 'webhooks'];

        $initial = [];
        foreach ($stores as $s) {
            $sid = (int) $s->id;
            $sp = $prefs['stores'][(string) $sid] ?? ($prefs['stores'][$sid] ?? []);

            $events = [];
            foreach ($eventKeys as $e) {
                $events[$e] = ($sp['events'][$e] ?? true) !== false;
            }

            $branches = [];
            foreach (Branch::where('store_id', $sid)->orderBy('id')->get(['id', 'name']) as $b) {
                $bid = (int) $b->id;
                $branches[] = [
                    'id' => $bid,
                    'name' => $b->name,
                    'enabled' => ($sp['branches'][(string) $bid] ?? ($sp['branches'][$bid] ?? true)) !== false,
                ];
            }

            $initial[] = [
                'id' => $sid,
                'title' => $s->title,
                'enabled' => ($sp['enabled'] ?? true) !== false,
                'events' => $events,
                'branches' => $branches,
            ];
        }

        return view('owner.settings.notification-preferences', [
            'initial' => $initial,        // NOT `settings` (view-settings-collision)
            'eventKeys' => $eventKeys,
        ]);
    }

    /**
     * M2: persist the prefs. The M1 fence (dashnotif_save_prefs) strips any store
     * id not owned/staffed by this user and any branch id not real for that store,
     * so a tampered request body can never write — or probe — a foreign store.
     */
    public function saveNotificationPrefs(Request $request): JsonResponse
    {
        $input = $request->validate([
            'prefs' => 'nullable|array',
            'prefs.stores' => 'nullable|array',
        ]);

        $clean = dashnotif_save_prefs($input['prefs'] ?? []);

        return response()->json(['ok' => true, 'prefs' => $clean]);
    }

    /** Upload a custom notification sound for the context store (owner-only). */
    public function uploadSound(Request $request)
    {
        $store = dashboard_current_store();
        abort_unless($store, 404, 'Pick a store first.');

        $request->validate([
            'sound' => ['required', 'file', 'mimetypes:audio/mpeg,audio/ogg,audio/wav,audio/x-wav,audio/wave', 'max:1024'], // ≤1MB
        ]);

        $file = $request->file('sound');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'mp3');
        $ext = in_array($ext, ['mp3', 'ogg', 'wav'], true) ? $ext : 'mp3';
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'sound';
        $filename = $name.'-'.Str::lower(Str::random(6)).'.'.$ext;

        // Cap custom sounds at 10 per store.
        $disk = Storage::disk('public');
        if (count($disk->files('notif-sounds/'.$store->id)) >= 10) {
            return response()->json([
                'ok' => false,
                'message' => ___('You can keep up to 10 custom sounds. Delete one first.'),
            ], 422);
        }

        $ownerId = (int) $store->user_id;
        if ($ownerId && ! StorageQuota::allow($ownerId, $file->getSize())) {
            return response()->json([
                'ok' => false,
                'message' => ___('Storage limit reached. Delete some files or upgrade your plan.'),
            ], 422);
        }

        $file->storeAs('notif-sounds/'.$store->id, $filename, 'public');

        if ($ownerId) {
            StorageQuota::add($ownerId, $file->getSize());
        }

        return response()->json(['ok' => true, 'library' => notification_sound_library((int) $store->id)]);
    }

    /** Delete a custom sound (store-scoped; only custom `/storage/...` ids). */
    public function deleteSound(Request $request)
    {
        $store = dashboard_current_store();
        abort_unless($store, 404, 'Pick a store first.');

        $id = (string) $request->input('id');
        $prefix = '/storage/notif-sounds/'.$store->id.'/';
        abort_unless(str_starts_with($id, $prefix) && ! str_contains($id, '..'), 422, 'Not a custom sound for this store.');

        $rel = substr($id, strlen('/storage/'));
        $disk = Storage::disk('public');
        $size = $disk->exists($rel) ? (int) $disk->size($rel) : 0;
        $disk->delete($rel);
        if ($size > 0) {
            StorageQuota::sub((int) $store->user_id, $size);
        }

        return response()->json(['ok' => true, 'library' => notification_sound_library((int) $store->id)]);
    }

    /**
     * R1: resolve the dashboard reporting window from the request.
     * Presets: today | 7d | 30d | month (default) | custom (from/to).
     * Returns the window plus the equal-length previous window for trend comparison.
     *
     * @return array{period:string,compare:bool,start:Carbon,end:Carbon,prevStart:Carbon,prevEnd:Carbon,label:string,from:string,to:string}
     */
    private function resolvePeriod(): array
    {
        // P1-4: single source — the shared period model (session-persisted, "vs last week").
        return resolve_dashboard_period();
    }

    private function resolvePeriodLegacy(): array
    {
        $period = (string) request('period', 'month');
        if (! in_array($period, ['today', '7d', '30d', 'month', 'custom'], true)) {
            $period = 'month';
        }
        $compare = (string) request('compare', '1') !== '0';
        $now = Carbon::now();

        switch ($period) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case '7d':
                $start = $now->copy()->subDays(6)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case '30d':
                $start = $now->copy()->subDays(29)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'custom':
                try {
                    $start = request('from') ? Carbon::parse(request('from'))->startOfDay() : $now->copy()->startOfMonth();
                } catch (\Throwable $e) {
                    $start = $now->copy()->startOfMonth();
                }
                try {
                    $end = request('to') ? Carbon::parse(request('to'))->endOfDay() : $now->copy()->endOfDay();
                } catch (\Throwable $e) {
                    $end = $now->copy()->endOfDay();
                }
                if ($end->lt($start)) {
                    [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
                }
                break;
            case 'month':
            default:
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;
        }

        // Equal-length window immediately preceding (calendar month for 'month').
        if ($period === 'month') {
            $prevStart = $start->copy()->subMonthNoOverflow()->startOfMonth();
            $prevEnd = $start->copy()->subMonthNoOverflow()->endOfMonth();
        } else {
            $lengthSeconds = $end->getTimestamp() - $start->getTimestamp();
            $prevEnd = $start->copy()->subSecond();
            $prevStart = $prevEnd->copy()->subSeconds($lengthSeconds);
        }

        $labels = [
            'today' => ___('Today'),
            '7d' => ___('Last 7 days'),
            '30d' => ___('Last 30 days'),
            'month' => ___('This month'),
            'custom' => ___('Custom range'),
        ];

        return [
            'period' => $period,
            'compare' => $compare,
            'start' => $start,
            'end' => $end,
            'prevStart' => $prevStart,
            'prevEnd' => $prevEnd,
            'label' => $labels[$period] ?? $labels['month'],
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
        ];
    }

    /**
     * Dismiss the onboarding checklist for this user (persisted in user_options).
     */
    public function dismissOnboarding()
    {
        $user = request()->user();
        UserOption::updateUserOption($user->id, 'onboarding_dismissed', '1');

        return response()->json(['ok' => true]);
    }

    /** P6-47: hide the "secure your account" prompt for this user (users.preferences). */
    public function dismissSecurityNudge(): JsonResponse|RedirectResponse
    {
        UserSettings::set((int) request()->user()->id, 'security_nudge_dismissed', '1');

        if (request()->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->back();
    }

    // P0-7: bring the onboarding checklist back after dismissal (from Settings).
    public function restoreOnboarding()
    {
        $user = request()->user();
        UserSettings::forget((int) $user->id, 'onboarding_dismissed');

        app_alert(___('Setup checklist restored.'));

        return redirect()->to(
            LaravelLocalization::getLocalizedURL(null, '/dashboard')
        );
    }

    // #29: Show reviews for a store
    public function reviews($store)
    {
        $store = Store::where('user_id', auth()->id())
            ->where(function ($q) use ($store) {
                $q->idOrSlug($store);
            })->firstOrFail();

        $reviews = MenuReview::where('store_id', $store->id)
            ->with('menu')
            ->latest()
            ->paginate(20);

        return view('owner.customers.reviews', compact('store', 'reviews'));
    }

    // #29: Approve / reject a review (or restore a rejected one to pending).
    // P6-283: status is validated, never silently defaulted; the item-level average
    // only counts reviews where order_id IS NULL — order-anchored store reviews
    // (P6-285) live on the same menu row for FK-anchoring only and must NOT
    // pull the item's rating_avg down.
    public function updateReview(Request $request, $store, $review)
    {
        $store = Store::where('user_id', auth()->id())
            ->where(function ($q) use ($store) {
                $q->idOrSlug($store);
            })->firstOrFail();

        $data = $request->validate([
            'status' => 'required|in:approved,rejected,pending',
        ]);

        $rev = MenuReview::where('store_id', $store->id)->findOrFail($review);
        $rev->update(['status' => $data['status']]);

        // P6-285: order-anchored reviews are stored on menu_id = first_line_item.id
        // for the FK only and must NEVER count toward the item's rating_avg. The
        // item rating is the diner's verdict on the dish, not the store's shipping.
        $avg = MenuReview::where('menu_id', $rev->menu_id)
            ->where('status', 'approved')
            ->whereNull('order_id')
            ->avg('stars');
        $cnt = MenuReview::where('menu_id', $rev->menu_id)
            ->where('status', 'approved')
            ->whereNull('order_id')
            ->count();
        Menu::where('id', $rev->menu_id)->update([
            'rating_avg' => round((float) ($avg ?? 0), 2),
            'rating_count' => (int) $cnt,
        ]);

        return response()->json(['ok' => true]);
    }

    public function saveGoal(Request $request)
    {
        $user = request()->user();
        $request->validate([
            'goal' => 'nullable|integer|min:0|max:9999999',
            'goal_type' => 'nullable|in:scans,orders,revenue',
            'goal_value' => 'nullable|integer|min:0|max:9999999',
        ]);

        if ($request->filled('goal_type') || $request->filled('goal_value')) {
            $goalType = $request->input('goal_type', 'scans');
            $goalValue = (int) $request->input('goal_value', 0);

            if ($goalValue > 0) {
                UserOption::updateUserOption($user->id, 'dashboard_goal_type', $goalType);
                UserOption::updateUserOption($user->id, 'dashboard_goal_value', $goalValue);

                if ($goalType === 'scans') {
                    UserOption::updateUserOption($user->id, 'scan_goal_month', $goalValue);
                } else {
                    UserSettings::forget((int) $user->id, 'scan_goal_month');
                }
            } else {
                foreach (['dashboard_goal_type', 'dashboard_goal_value', 'scan_goal_month'] as $goalKey) {
                    UserSettings::forget((int) $user->id, $goalKey);
                }
            }

            return response()->json([
                'ok' => true,
                'goal_type' => $goalValue > 0 ? $goalType : null,
                'goal_value' => $goalValue > 0 ? $goalValue : null,
            ]);
        }

        $goal = (int) $request->input('goal', 0);
        if ($goal > 0) {
            UserOption::updateUserOption($user->id, 'scan_goal_month', $goal);
            UserOption::updateUserOption($user->id, 'dashboard_goal_type', 'scans');
            UserOption::updateUserOption($user->id, 'dashboard_goal_value', $goal);
        } else {
            foreach (['scan_goal_month', 'dashboard_goal_type', 'dashboard_goal_value'] as $goalKey) {
                UserSettings::forget((int) $user->id, $goalKey);
            }
        }

        return response()->json(['ok' => true, 'goal' => $goal ?: null]);
    }
}
