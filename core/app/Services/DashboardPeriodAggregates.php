<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\Money;
use Juwri\Core\Support\Sql;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Models\OrderItemExtra;

/**
 * P6-62: the owner dashboard's current-window figures (order count, revenue, by-day series, top items) as SQL
 * aggregates instead of hydrating every order with its item graph.
 *
 * Money is summed in integer MINOR units, grouped by the order's own currency — a multi-currency window is never
 * added into one number: the revenue figures are reported for ONE currency (the largest by value) and the other
 * currencies are returned separately in `revenueMinorByCurrency`.
 *
 * Definitions are the ones the old PHP loop used: revenue = Order::scopeRevenueCounted; an order's revenue is its
 * frozen grand_total_minor, falling back to the live `total` accessor ONLY for a still-unsnapshotted row; a top
 * item's revenue is (line unit_price | variant price | menu price + extras + option upcharges) x quantity.
 */
final class DashboardPeriodAggregates
{
    /**
     * @param  list<int|string>  $storeIds
     * @param  list<string>  $dayLabels  the chart buckets, 'd M' labels, oldest first
     * @return array{
     *     orderCount: int, revenueOrderCount: int, salesRevenue: float, currency: string|null, currencyBasis: string,
     *     revenueMinorByCurrency: array<string, int>, ordersByDay: array<string, int>, salesByDay: array<string, float>,
     *     topItems: list<array{name: string, image: mixed, qty: int, revenue: float}>
     * }
     */
    public static function compute(array $storeIds, ?int $branchId, Carbon $start, Carbon $end, array $dayLabels, ?string $preferredCurrency = null): array
    {
        $scope = fn () => Order::query()->whereIn('store_id', $storeIds)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$start, $end]);

        // 1. ONE grouped query: day x currency x revenue-flag -> orders, snapshot minor sum, unsnapshotted count.
        $day = Sql::date('created_at');
        // is_paid is bound (not inlined as `= 1`): PostgreSQL types it boolean and rejects boolean = integer literals.
        $rev = '(status = ? OR is_paid = ?)';
        $revBind = ['completed', 1];
        $rows = $scope()->toBase()
            ->selectRaw("{$day} as d, store_id as sid, currency_code as cur, COUNT(*) as n")
            ->selectRaw("SUM(CASE WHEN {$rev} THEN 1 ELSE 0 END) as rn", $revBind)
            ->selectRaw("SUM(CASE WHEN {$rev} AND grand_total_minor IS NOT NULL THEN grand_total_minor ELSE 0 END) as minor", $revBind)
            ->selectRaw("SUM(CASE WHEN {$rev} AND grand_total_minor IS NULL THEN 1 ELSE 0 END) as gap", $revBind)
            ->groupByRaw("{$day}, store_id, currency_code")
            ->get();

        $orderCount = 0;
        $ordersByDay = array_fill_keys($dayLabels, 0);
        /** @var array<string, array<string, int>> $minorByDayCur  label => currency => minor */
        $minorByDayCur = [];
        $revenueMinorByCurrency = [];
        $revenueCountByCurrency = [];
        $hasGap = false;
        foreach ($rows as $r) {
            $label = date_formating((string) $r->d, 'd M');
            $n = (int) $r->n;
            $orderCount += $n;
            if (array_key_exists($label, $ordersByDay)) {
                $ordersByDay[$label] += $n;
            }
            if ((int) $r->rn === 0) {
                continue;
            }
            $cur = self::orderCurrency($r->cur, (int) $r->sid);
            $revenueCountByCurrency[$cur] = ($revenueCountByCurrency[$cur] ?? 0) + (int) $r->rn;
            $revenueMinorByCurrency[$cur] = ($revenueMinorByCurrency[$cur] ?? 0) + (int) $r->minor;
            $minorByDayCur[$label][$cur] = ($minorByDayCur[$label][$cur] ?? 0) + (int) $r->minor;
            $hasGap = $hasGap || (int) $r->gap > 0;
        }

        // 2. Legacy rows with no snapshot yet: the accessor is the only source, so hydrate JUST those (empty after backfill).
        if ($hasGap) {
            $gaps = $scope()->revenueCounted()->whereNull('grand_total_minor')
                ->with(['items.menu', 'items.itemExtras.extra', 'items.variant'])->get();
            foreach ($gaps as $o) {
                $cur = self::orderCurrency($o->currency_code, (int) $o->store_id);
                $minor = Money::minorFromMajor((float) $o->total, $cur);
                $label = date_formating($o->created_at, 'd M');
                $revenueMinorByCurrency[$cur] = ($revenueMinorByCurrency[$cur] ?? 0) + $minor;
                $minorByDayCur[$label][$cur] = ($minorByDayCur[$label][$cur] ?? 0) + $minor;
            }
        }

        // 3. The reporting currency (never "the biggest number": 1000 JPY is not more than 500 USD): the store's own
        //    currency when it has revenue in the window, else the currency with the most revenue-counted orders.
        $preferred = $preferredCurrency ? strtoupper($preferredCurrency) : null;
        $currencyBasis = 'store';
        if ($preferred !== null && isset($revenueMinorByCurrency[$preferred])) {
            $currency = $preferred;
        } elseif ($revenueCountByCurrency !== []) {
            $currencyBasis = 'orders';
            $currency = null;
            foreach ($revenueCountByCurrency as $cur => $n) {
                if ($currency === null || $n > $revenueCountByCurrency[$currency]) {
                    $currency = $cur;
                }
            }
        } else {
            $currency = $preferred;
        }

        $salesByDay = array_fill_keys($dayLabels, 0.0);
        foreach ($minorByDayCur as $label => $perCur) {
            if ($currency !== null && array_key_exists($label, $salesByDay) && isset($perCur[$currency])) {
                $salesByDay[$label] = Money::majorFromMinor($perCur[$currency], $currency);
            }
        }

        return [
            'orderCount' => $orderCount,
            'revenueOrderCount' => $currency === null ? 0 : ($revenueCountByCurrency[$currency] ?? 0),
            'salesRevenue' => ($currency === null || ! isset($revenueMinorByCurrency[$currency])) ? 0.0 : Money::majorFromMinor($revenueMinorByCurrency[$currency], $currency),
            'currency' => $currency,
            'currencyBasis' => $currencyBasis,
            'revenueMinorByCurrency' => $revenueMinorByCurrency,
            'ordersByDay' => $ordersByDay,
            'salesByDay' => $salesByDay,
            'topItems' => self::topItems($scope, $currency, $storeIds),
        ];
    }

    /** An order's currency: its own stamp, else its STORE's currency (never the platform default, unless the store has none). */
    private static function orderCurrency(?string $stamped, int $storeId): string
    {
        return $stamped ? strtoupper($stamped) : strtoupper(store_currency($storeId));
    }

    /**
     * Restrict an orders query to one currency: orders stamped with it, plus unstamped legacy orders of stores whose
     * own currency it is. The ONE definition, shared by the current-window top items and the previous-window comparison.
     *
     * @template T of Builder|\Illuminate\Database\Eloquent\Relations\Relation
     *
     * @param  T  $query
     * @param  list<int|string>  $storeIds
     * @return T
     */
    public static function inCurrency($query, ?string $currency, array $storeIds)
    {
        if ($currency === null) {
            return $query;
        }
        $currency = strtoupper($currency);
        $legacyStores = array_values(array_filter($storeIds, fn ($id) => strtoupper(store_currency((int) $id)) === $currency));

        return $query->where(function ($w) use ($currency, $legacyStores) {
            $w->whereRaw('UPPER(currency_code) = ?', [$currency]);
            if ($legacyStores !== []) {
                $w->orWhere(fn ($n) => $n->where(fn ($b) => $b->whereNull('currency_code')->orWhere('currency_code', ''))->whereIn('store_id', $legacyStores));
            }
        });
    }

    /**
     * Top 5 items by quantity among revenue-counted orders in $currency. Base price and extras are summed in SQL;
     * only the option-upcharge JSON (not portable SQL) is read from the 5 winning items' lines that carry options.
     *
     * @param  \Closure(): Builder<Order>  $scope
     * @param  list<int|string>  $storeIds
     * @return list<array{name: string, image: mixed, qty: int, revenue: float}>
     */
    private static function topItems(\Closure $scope, ?string $currency, array $storeIds): array
    {
        if ($currency === null) {
            return [];
        }

        $oi = (new OrderItem)->getTable();
        $variants = (new MenuVariant)->getTable();
        $menus = (new Menu)->getTable();
        $extras = (new MenuExtra)->getTable();
        $ie = (new OrderItemExtra)->getTable();

        $lines = function () use ($scope, $currency, $storeIds, $oi): QueryBuilder {
            $ids = self::inCurrency($scope()->revenueCounted(), $currency, $storeIds)
                ->select('id')->toBase();

            return DB::table($oi)->whereIn("{$oi}.order_id", $ids);
        };

        $extrasSub = DB::table($ie)
            ->leftJoin($extras, "{$extras}.id", '=', "{$ie}.extra_id")
            ->groupBy("{$ie}.order_item_id")
            ->selectRaw("{$ie}.order_item_id as xl, SUM(COALESCE({$ie}.price_minor, {$extras}.price_minor, 0)) as xm");

        $top = $lines()
            ->leftJoin($menus, fn ($j) => $j->on("{$menus}.id", '=', "{$oi}.item_id")->whereNull("{$menus}.deleted_at"))
            ->leftJoin($variants, "{$variants}.id", '=', "{$oi}.variation")
            ->leftJoinSub($extrasSub, 'xs', 'xs.xl', '=', "{$oi}.id")
            ->groupBy("{$oi}.item_id")
            ->selectRaw("{$oi}.item_id as item_id, SUM({$oi}.quantity) as qty, MIN({$oi}.id) as first_line")
            ->selectRaw("SUM(COALESCE({$oi}.unit_price_minor, CASE WHEN {$oi}.variation IS NOT NULL AND {$oi}.variation <> 0 AND {$variants}.id IS NOT NULL "
                ."THEN {$variants}.price_minor ELSE COALESCE({$menus}.price_minor, 0) END) * {$oi}.quantity) as base_minor")
            ->selectRaw("SUM(COALESCE(xs.xm, 0) * {$oi}.quantity) as extras_minor")
            ->orderByRaw('SUM('."{$oi}.quantity) DESC, MIN({$oi}.id) ASC")
            ->limit(5)
            ->get();

        if ($top->isEmpty()) {
            return [];
        }

        // Option upcharges (JSON) for the winning items' lines, summed in minor units.
        $itemIds = $top->pluck('item_id')->filter(fn ($v) => $v !== null)->all();
        $optionRows = $lines()->whereNotNull("{$oi}.options")
            ->where(fn ($q) => $q->whereIn("{$oi}.item_id", $itemIds)->when($top->contains(fn ($t) => $t->item_id === null), fn ($w) => $w->orWhereNull("{$oi}.item_id")))
            ->get(["{$oi}.item_id", "{$oi}.quantity", "{$oi}.options"]);
        $optionMinor = [];
        foreach ($optionRows as $row) {
            $groups = json_decode((string) $row->options, true);
            $sum = 0;
            foreach ((array) $groups as $grp) {
                foreach ((array) ($grp['selected'] ?? []) as $opt) {
                    $sum += isset($opt['price_minor']) && is_numeric($opt['price_minor'])
                        ? (int) $opt['price_minor']
                        : Money::minorFromMajor((float) ($opt['price'] ?? 0), $currency);
                }
            }
            $key = $row->item_id === null ? '' : (string) $row->item_id;
            $optionMinor[$key] = ($optionMinor[$key] ?? 0) + $sum * (int) $row->quantity;
        }

        $menuRows = Menu::whereIn('id', $itemIds)->get()->keyBy('id');

        $out = [];
        foreach ($top as $t) {
            $key = $t->item_id === null ? '' : (string) $t->item_id;
            $menu = $t->item_id === null ? null : $menuRows->get($t->item_id);
            $minor = (int) $t->base_minor + (int) $t->extras_minor + ($optionMinor[$key] ?? 0);
            $out[] = [
                'name' => $menu?->getRawOriginal('name') ?: ___('Unknown item'),
                'image' => $menu?->image,
                'qty' => (int) $t->qty,
                'revenue' => Money::majorFromMinor($minor, $currency),
            ];
        }

        return $out;
    }
}
