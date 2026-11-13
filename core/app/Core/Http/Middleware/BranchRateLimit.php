<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\AbuseGuard;

/**
 * Abuse Guard P7 (7.4): per-restaurant / per-branch ceilings on public WRITE
 * endpoints (orders, waitlist, reservations). Customer IPs vary, so user/IP-keyed
 * limiters never see "one merchant's 40 branches" — this dimension is keyed by the
 * restaurant (and branch when the route carries it).
 *
 * Defaults are HIGH — legitimate ceilings, not throttles. Routes through
 * AbuseGuard::hit('branch', …): warn logs+allows, enforce 429s. Public menu READS
 * are never limited — menus never go dark.
 *
 * Usage: ->middleware('branchlimit:orders' | 'waitlist' | 'reservation')
 */
class BranchRateLimit
{
    public const GUARD = 'branch';

    /** kind => per-minute default ceiling. */
    public const DEFAULTS = [
        'orders' => 300,
        'waitlist' => 60,
        'reservation' => 60,
    ];

    public function handle(Request $request, Closure $next, string $kind)
    {
        if (AbuseGuard::mode(self::GUARD) === AbuseGuard::OFF) {
            return $next($request);
        }

        $perMin = (int) AbuseGuard::value(self::GUARD, $kind.'_per_min', self::DEFAULTS[$kind] ?? 300);
        if ($perMin <= 0) {
            return $next($request);
        }

        $storeId = $this->resolveRestaurantId($request);
        if (! $storeId) {
            return $next($request); // can't attribute → don't block
        }
        $branchId = $request->route('branch') ?? $request->input('branch_id');
        $dimension = $storeId.($branchId ? ':b'.$branchId : '');

        $rlKey = 'abuse:branch:'.$kind.':'.$dimension;

        if (RateLimiter::tooManyAttempts($rlKey, $perMin)) {
            $ctx = [
                'meta' => ['kind' => $kind, 'store_id' => $storeId, 'branch_id' => $branchId, 'per_min' => $perMin],
                'ip' => $request->ip(),
            ];
            if (! AbuseGuard::hit(self::GUARD, $kind, $ctx)) {
                return response()->json([
                    'message' => 'This location is receiving too many requests right now. Please try again shortly.',
                ], 429)->header('Retry-After', RateLimiter::availableIn($rlKey));
            }
        }

        RateLimiter::hit($rlKey, 60);

        return $next($request);
    }

    protected function resolveRestaurantId(Request $request): ?int
    {
        $store = $request->route('store');

        // SubstituteBindings (group middleware) runs BEFORE route middleware, so
        // {store} may already be a bound Post model — not a numeric string.
        if ($store instanceof Store) {
            return (int) $store->id;
        }
        if ($store && is_numeric($store)) {
            return (int) $store;
        }
        // Non-numeric string param (route also matches slugs pre-binding).
        if (is_string($store) && $store !== '') {
            $id = Store::where('slug', $store)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        $slug = $request->route('slug');
        if (is_string($slug) && $slug !== '') {
            $id = Store::where('slug', $slug)->value('id');

            return $id ? (int) $id : null;
        }

        return null;
    }
}
