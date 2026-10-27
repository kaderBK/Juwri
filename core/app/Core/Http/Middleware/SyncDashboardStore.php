<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;

/**
 * Keeps the sidebar store switcher in sync: visiting any URL that carries a
 * restaurant parameter makes that restaurant the session-selected store, so
 * every other dashboard page follows it.
 *
 * It also prices the request in that store's currency, so every owner screen
 * (analytics, reports, orders) shows the store's money and not the platform
 * default — except the platform-billing screens, which price platform money
 * (plans, wallet, add-ons, credits, apps, invoices) in the platform currency.
 */
class SyncDashboardStore
{
    /**
     * Routes that show or write PLATFORM money (the owner's plan, wallet, add-ons,
     * AI credits, app/theme store, invoices): they keep the platform currency, or
     * a SAR store selected in the sidebar would price a USD plan, top-up or invoice
     * in riyals — and stamp it so.
     */
    public const PLATFORM_BILLING_ROUTES = [
        'checkout.*',
        'subscription', 'subscription.*', 'spotlight.*', 'feature.locked',
        'wallet.*',
        'addons.*',
        'credits.*',
        'transactions', 'invoice', 'invoice.*',
        'apps.*',
        'user.store.apps', 'user.store.apps.*', 'user.store.themes', 'user.store.themes.*',
        'owner-app.billing.*',
    ];

    public function handle(Request $request, Closure $next)
    {
        // Background polls (orders heartbeat, KDS board) must not fight a
        // store/branch switch made in another tab — sync on page loads only.
        if ($request->ajax()) {
            $this->applyStoreCurrency($request);

            return $next($request);
        }

        $id = $this->restaurantIdFromRoute($request);

        if (! $id && $request->isMethod('GET') && is_numeric($request->query('store_id'))) {
            $id = (int) $request->query('store_id');
        }
        if (! $id
            && $request->isMethod('GET')
            && $request->routeIs('theme.customizer.page')
            && is_numeric($request->query('store'))
        ) {
            $id = (int) $request->query('store');
        }

        if ($id
            && $id !== (int) session('dashboard_store_id')
            && function_exists('dashboard_can_access_store')
            && dashboard_can_access_store($id)
        ) {
            session(['dashboard_store_id' => $id]);
            session()->forget('dashboard_branch_id');
        }

        // In-page branch pickers (?branch_id=) update the sidebar selection too.
        if ($request->isMethod('GET') && $request->has('branch_id')) {
            $branchId = (int) $request->query('branch_id');
            $storeId = (int) session('dashboard_store_id');

            if (! $branchId) {
                // Explicit "all branches" pick — 0 beats a saved default.
                session(['dashboard_branch_id' => 0]);
            } elseif ($storeId && Branch::where('id', $branchId)->where('store_id', $storeId)->exists()) {
                session(['dashboard_branch_id' => $branchId]);
            }
        }

        $this->applyStoreCurrency($request);

        return $next($request);
    }

    /**
     * Every owner screen must price in the WORKING store's currency: only a few
     * controllers loaded the store's currency_code/sign/pos, so analytics,
     * reports and the other owner screens priced everything in the platform
     * default (USD) for a SAR store. Platform-billing screens are the exception
     * ({@see self::PLATFORM_BILLING_ROUTES}). The working store is the one the
     * owner switched to (route/session), so its currency is applied once per
     * dashboard request, before any screen renders.
     */
    private function applyStoreCurrency(Request $request): void
    {
        if ($request->route() !== null && $request->routeIs(...self::PLATFORM_BILLING_ROUTES)) {
            return;
        }

        $store = dashboard_current_store();

        if (! $store) {
            return;
        }

        // The swap below is DISPLAY only. Remember the platform currency FIRST so
        // wallet/ledger money written later in this request is not stamped with
        // the store's currency (bug batch 10 · 1).
        remember_platform_currency();

        $sign = store_options($store->id, 'currency_sign');
        if ($this->hasStoreValue($sign)) {
            config(['settings.currency_sign' => $sign]);
        }

        // store_currency_pos() keeps a stored 0 (symbol AFTER the price) and only
        // falls back to the platform default when the store never chose one.
        $pos = store_options($store->id, 'currency_pos');
        if ($this->hasStoreValue($pos)) {
            config(['settings.currency_pos' => store_currency_pos($store->id)]);
        }

        $code = store_options($store->id, 'currency_code');
        if ($this->hasStoreValue($code)) {
            config(['settings.currency_code' => (string) $code]);
        }

        // Plain pass-through of the store's own RTL-auto flag, when it set one.
        $rtl = store_options($store->id, 'currency_rtl_auto');
        if ($this->hasStoreValue($rtl)) {
            config(['settings.currency_rtl_auto' => $rtl]);
        }
    }

    /**
     * A store option that carries a usable value: store_options() returns FALSE
     * for an unset key, and an empty string must never blank the platform value.
     */
    private function hasStoreValue(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && (string) $value !== '';
    }

    private function restaurantIdFromRoute(Request $request): ?int
    {
        $route = $request->route();

        if (! $route) {
            return null;
        }

        foreach (['store', 'storeId'] as $name) {
            $value = $route->parameter($name);

            if ($value === null) {
                continue;
            }

            if ($value instanceof Store) {
                return (int) $value->id;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }

            if (is_string($value)) {
                return (int) Store::where('slug', $value)->value('id') ?: null;
            }
        }

        return null;
    }
}
