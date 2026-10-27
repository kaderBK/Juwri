<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Contracts\Billing\PlanQuota;
use Juwri\Core\Models\Store;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\Dispute;

/**
 * P2-2: "needs attention" exceptions for the owner dashboard. Each entry is one
 * actionable line with a FIX link. Every query is restricted to the passed-in
 * store ids (the sidebar context / accessible set) so nothing cross-tenant leaks.
 * All checks are guarded — a missing feature simply contributes no exceptions.
 */
class DashboardAttentionService
{
    /** Orders unpaid/stuck for at least this many hours count as needing attention. */
    public const STUCK_HOURS = 2;

    /**
     * @param  int[]  $storeIds
     * @return array<int,array{type:string,label:string,count:int,url:string,severity:string}>
     */
    public static function for(array $storeIds): array
    {
        $storeIds = array_values(array_filter(array_map('intval', $storeIds)));
        if (empty($storeIds)) {
            return [];
        }

        $out = [];
        $first = $storeIds[0];
        $u = fn (string $route, $params = []) => Route::has($route) ? route($route, $params) : '#';

        // 1. Stuck orders — unpaid and older than STUCK_HOURS, not completed/cancelled.
        try {
            $stuck = Order::whereIn('store_id', $storeIds)
                ->where('is_paid', 0)
                ->whereNotIn('status', ['completed', 'cancelled', 'delivered', 'refunded'])
                ->where('created_at', '<=', Carbon::now()->subHours(self::STUCK_HOURS))
                ->count();
            if ($stuck > 0) {
                $out[] = self::row('orders', trans_choice(':count unpaid order needs attention|:count unpaid orders need attention', $stuck, ['count' => $stuck]), $stuck, $u('stores.orders', $first), 'danger');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // 2. Offline delivery channels — connection not healthy or last sync errored.
        try {
            $offline = AggregatorConnection::whereIn('store_id', $storeIds)
                ->where(function ($w) {
                    $w->whereNotIn('status', ['connected', 'active', 'online'])
                        ->orWhereNotNull('sync_error');
                })->count();
            if ($offline > 0) {
                $out[] = self::row('channels', trans_choice(':count delivery channel is offline|:count delivery channels are offline', $offline, ['count' => $offline]), $offline, $u('stores.aggregators.index'), 'warning');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // 3. Pending disputes.
        try {
            $disputes = Dispute::whereIn('store_id', $storeIds)
                ->whereNotIn('status', ['won', 'lost', 'warning_closed', 'charge_refunded'])
                ->count();
            if ($disputes > 0) {
                $out[] = self::row('disputes', trans_choice(':count open dispute|:count open disputes', $disputes, ['count' => $disputes]), $disputes, $u('disputes.index'), 'danger');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // 4. Low-stock ingredients — active, with a par level, at or below it.
        try {
            $low = Ingredient::whereIn('store_id', $storeIds)
                ->where('active', 1)
                ->where('par_level', '>', 0)
                ->whereColumn('current_stock', '<=', 'par_level')
                ->count();
            if ($low > 0) {
                $out[] = self::row('stock', trans_choice(':count ingredient is low on stock|:count ingredients are low on stock', $low, ['count' => $low]), $low, $u('inventory.index'), 'warning');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // 5. Low-stock MENU items — inventory-tracked, at or below their threshold. (D7)
        try {
            $lowItems = Menu::whereIn('store_id', $storeIds)
                ->where('track_inventory', 1)
                ->whereNotNull('low_stock_threshold')
                ->whereColumn('stock_qty', '<=', 'low_stock_threshold')
                ->count();
            if ($lowItems > 0) {
                $out[] = self::row('menu_stock', trans_choice(':count menu item is low on stock|:count menu items are low on stock', $lowItems, ['count' => $lowItems]), $lowItems, $u('stores.menu', $first).'?lowstock=1', 'warning');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // 6. Sold-out (86'd) menu items — one-click un-86 from the card. (D7)
        try {
            $soldOut = Menu::whereIn('store_id', $storeIds)->where('is_sold_out', 1)->count();
            if ($soldOut > 0) {
                $out[] = self::row('menu_soldout', trans_choice(":count item is sold out (86'd)|:count items are sold out (86'd)", $soldOut, ['count' => $soldOut]), $soldOut, $u('stores.menu', $first).'?soldout=1', 'warning', $u('stores.menu.un86', $first));
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // 7. E3: plan monthly-order SOFT limit reached — a banner only, NEVER a
        // block. Resolved per owner (all $storeIds share one owner in a dashboard
        // context); absent plan key contributes nothing.
        try {
            $owner = Store::where('id', $first)->first()?->user;
            if ($owner) {
                $soft = app(PlanQuota::class)->orderSoftStatus($owner);
                if ($soft !== null && $soft['used'] >= $soft['limit']) {
                    $out[] = self::row('orders_soft', ___('You have reached your plan\'s monthly order allowance — upgrade to keep growing.'), $soft['used'], $u('subscription'), 'warning');
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // 8. P3-06b (API-21, API-27, API-32): late payments of headless online orders waiting for the owner (accept
        // or refund), and accepted card holds whose authorisation lapses within a day (the sweep voids them then).
        try {
            $late = self::latePaymentsWaiting(Order::whereIn('store_id', $storeIds))->count();
            if ($late > 0) {
                $out[] = self::row('late_payments', trans_choice(':count late payment waits for your decision|:count late payments wait for your decision', $late, ['count' => $late]), $late, $u('stores.latePayments.index').'?store_id='.$first, 'danger');
            }
            $lapsing = Order::whereIn('store_id', $storeIds)
                ->where('status', Order::STATUS_PENDING_APPROVAL)
                ->where('late_payment_status', 'accepted')
                ->whereNotNull('authorisation_expires_at')
                ->whereBetween('authorisation_expires_at', [Carbon::now(), Carbon::now()->addDay()])
                ->count();
            if ($lapsing > 0) {
                $out[] = self::row('late_holds', trans_choice(':count card hold lapses within 24 hours — pick a time or refund|:count card holds lapse within 24 hours — pick a time or refund', $lapsing, ['count' => $lapsing]), $lapsing, $u('stores.latePayments.index').'?store_id='.$first, 'danger');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // 9. Lane C: booking deposit refunds the gateway layer refused on cancel — the customer has not been paid back
        // yet; the bookings page of the (first) store with one offers "Retry refund".
        try {
            $refunds = DB::table('booking_pending_refunds')->whereIn('store_id', $storeIds)->where('status', 'pending');
            $pending = (clone $refunds)->count();
            if ($pending > 0) {
                $at = (int) $refunds->orderBy('id')->value('store_id');
                $out[] = self::row('deposit_refunds', trans_choice(':count deposit refund was refused — retry it|:count deposit refunds were refused — retry them', $pending, ['count' => $pending]), $pending, $u('bookings.owner.index', ['store_id' => $at]), 'danger');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $out;
    }

    /**
     * Per-store count of attention CATEGORIES that are non-zero — the number the
     * multi-store fleet strip shows. Runs one grouped query per check (6 total)
     * instead of calling for() once per store (9 × N). Keep the checks in sync
     * with for().
     *
     * @param  int[]  $storeIds
     * @return array<int,int> storeId => number of non-zero attention categories
     */
    public static function countsByStore(array $storeIds): array
    {
        $storeIds = array_values(array_filter(array_map('intval', $storeIds)));
        if (empty($storeIds)) {
            return [];
        }

        $categories = [];
        $tally = function ($grouped) use (&$categories) {
            foreach ($grouped as $sid => $c) {
                if ((int) $c > 0) {
                    $categories[(int) $sid] = ($categories[(int) $sid] ?? 0) + 1;
                }
            }
        };
        $grp = fn ($q) => $q->groupBy('store_id')->selectRaw('store_id, count(*) as c')->pluck('c', 'store_id');

        try {
            $tally($grp(Order::whereIn('store_id', $storeIds)->where('is_paid', 0)
                ->whereNotIn('status', ['completed', 'cancelled', 'delivered', 'refunded'])
                ->where('created_at', '<=', Carbon::now()->subHours(self::STUCK_HOURS))));
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $tally($grp(AggregatorConnection::whereIn('store_id', $storeIds)
                ->where(fn ($w) => $w->whereNotIn('status', ['connected', 'active', 'online'])->orWhereNotNull('sync_error'))));
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $tally($grp(Dispute::whereIn('store_id', $storeIds)
                ->whereNotIn('status', ['won', 'lost', 'warning_closed', 'charge_refunded'])));
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $tally($grp(Ingredient::whereIn('store_id', $storeIds)->where('active', 1)
                ->where('par_level', '>', 0)->whereColumn('current_stock', '<=', 'par_level')));
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $tally($grp(Menu::whereIn('store_id', $storeIds)->where('track_inventory', 1)
                ->whereNotNull('low_stock_threshold')->whereColumn('stock_qty', '<=', 'low_stock_threshold')));
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $tally($grp(Menu::whereIn('store_id', $storeIds)->where('is_sold_out', 1)));
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $tally($grp(self::latePaymentsWaiting(Order::whereIn('store_id', $storeIds))));
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $tally($grp(DB::table('booking_pending_refunds')->whereIn('store_id', $storeIds)->where('status', 'pending')));
        } catch (\Throwable $e) {
            report($e);
        }

        return $categories;
    }

    /**
     * Late payments the owner still has to decide on: received after the window (order cancelled), or accepted but
     * waiting as pending approval because the slot or stock was gone.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Order>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Order>
     */
    private static function latePaymentsWaiting($query)
    {
        return $query->where(fn ($w) => $w
            ->where(fn ($q) => $q->where('late_payment_status', 'received_late')->where('status', 'cancelled'))
            ->orWhere(fn ($q) => $q->where('late_payment_status', 'accepted')->where('status', Order::STATUS_PENDING_APPROVAL)));
    }

    private static function row(string $type, string $label, int $count, string $url, string $severity, ?string $action = null): array
    {
        $row = ['type' => $type, 'label' => $label, 'count' => $count, 'url' => $url, 'severity' => $severity];
        if ($action && $action !== '#') {
            $row['action_url'] = $action; // D7: one-click CTA (e.g. un-86)
        }

        return $row;
    }
}
