<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\AbuseGuard;

/**
 * P6-74: per-STORE, per-DAY cost cap on public endpoints that spend our money (AI calls, SMS/WA OTP sends).
 * Per-IP throttles alone let a botnet burn one store's allowance; this caps the store itself.
 *
 * Usage: ->middleware('storecap:ai') or 'storecap:sms'. Counter = Cache key storecap:{bucket}:{storeId}:{Y-m-d},
 * the day is the UTC day (consistent across stores and servers; resets at 00:00 UTC). A request with no
 * resolvable store counts against the 'platform' key (id 0), so an unattributed caller cannot dodge the cap.
 *
 * Limits (0 = unlimited): config('abuse.store_daily_caps.<bucket>'), overridable by the admin setting
 * abuse_store_cap_<bucket>_daily, and per plan by abuse_store_cap_<bucket>_daily_<plan slug> (owner plan).
 * Mode follows AbuseGuard 'store_cap' (warn logs+allows, enforce -> 429 in the standard JSON error envelope).
 * The counter is incremented BEFORE the work runs (atomic, no race).
 */
class StoreDailyCap
{
    public const GUARD = 'store_cap';

    public function handle(Request $request, Closure $next, string $bucket = 'ai'): mixed
    {
        if (AbuseGuard::mode(self::GUARD) === AbuseGuard::OFF) {
            return $next($request);
        }

        $denied = $this->check($request, $bucket, $this->resolveStoreId($request));

        return $denied ?? $next($request);
    }

    /**
     * P6-261: count one hit for an explicit store (0 = platform bucket) — for endpoints whose store arrives in the
     * body and is only trusted after the controller validated it (checkout OTP: store has the gate on). Returns the
     * 429 response when a cap is hit (enforce mode), else null. `$extra` adds further daily ceilings
     * (scope => [key suffix, cap]) checked after the per-caller sub-cap and before the store cap, e.g. per phone.
     *
     * @param  array<string, array{0: string, 1: int}>  $extra
     */
    public function check(Request $request, string $bucket, int $storeId, array $extra = []): mixed
    {
        if (AbuseGuard::mode(self::GUARD) === AbuseGuard::OFF) {
            return null;
        }
        $cap = $this->capFor($bucket, $storeId);
        if ($cap <= 0) {
            return null;
        }

        $day = now('UTC')->format('Y-m-d');

        // Per-IP daily sub-cap, checked FIRST (P6-90): an address already over its sub-cap must not keep charging, and
        // so exhaust, the store counter. Keyed PER STORE (never global: carrier NAT / shared Wi-Fi puts many real
        // customers behind one IP, and one busy store must not lock that IP out of every other store).
        // A logged-in customer is counted by customer id, not IP (the IP is not their identity); guests by IP.
        $ipCap = (int) AbuseGuard::value(self::GUARD, $bucket.'_ip_daily', (int) config('abuse.store_daily_ip_caps.'.$bucket, 0));
        if ($ipCap > 0) {
            $customerId = $this->customerId();
            $who = $customerId ? 'cust:'.$customerId : 'ip:'.($request->ip() ?: 'noip');
            if ($this->over('storecap:'.$bucket.':'.$storeId.':'.$who.':'.$day, $ipCap, $request, $bucket, $storeId, $customerId ? 'customer' : 'ip')) {
                return $this->denied($bucket, 'Too many requests from your network today for this feature. Please try again tomorrow.');
            }
        }

        foreach ($extra as $scope => [$suffix, $extraCap]) {
            if ($extraCap > 0 && $this->over('storecap:'.$bucket.':'.$scope.':'.$suffix.':'.$day, $extraCap, $request, $bucket, $storeId, $scope)) {
                return $this->denied($bucket, 'Too many requests today for this feature. Please try again tomorrow.');
            }
        }

        if ($this->over('storecap:'.$bucket.':'.$storeId.':'.$day, $cap, $request, $bucket, $storeId, 'store')) {
            return $this->denied($bucket, 'This store has reached its daily limit for this feature. Please try again tomorrow.');
        }

        // P6-261: one platform-wide ceiling per bucket (0 = off) — a cost backstop across every store, set far above
        // any single store's cap so one store's traffic never reaches it.
        $global = (int) AbuseGuard::value(self::GUARD, $bucket.'_global_daily', (int) config('abuse.store_daily_global_caps.'.$bucket, 0));
        if ($global > 0 && $this->over('storecap:'.$bucket.':global:'.$day, $global, $request, $bucket, $storeId, 'global')) {
            return $this->denied($bucket, 'This feature has reached its daily limit. Please try again tomorrow.');
        }

        return null;
    }

    /** Atomically count one hit on $key; true when it is over $cap AND the guard is enforcing (warn logs and allows). */
    protected function over(string $key, int $cap, Request $request, string $bucket, int $storeId, string $scope): bool
    {
        Cache::add($key, 0, now('UTC')->endOfDay()->addHour());
        $used = (int) Cache::increment($key);
        if ($used <= $cap) {
            return false;
        }
        $ctx = ['ip' => $request->ip(), 'meta' => ['bucket' => $bucket, 'scope' => $scope, 'store_id' => $storeId, 'cap' => $cap, 'used' => $used]];

        return ! AbuseGuard::hit(self::GUARD, $bucket.'_daily_cap', $ctx);
    }

    protected function customerId(): int
    {
        try {
            return (int) (Auth::guard('customer')->id() ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function denied(string $bucket, string $message): mixed
    {
        return ApiError::response(429, 'daily_cap_exceeded', $message, null, ['bucket' => $bucket], [
            'Retry-After' => (string) max(1, (int) now('UTC')->diffInSeconds(now('UTC')->endOfDay()) + 1),
        ]);
    }

    protected function capFor(string $bucket, int $storeId): int
    {
        $default = (int) config('abuse.store_daily_caps.'.$bucket, 0);
        $cap = (int) AbuseGuard::value(self::GUARD, $bucket.'_daily', $default);

        $plan = $storeId ? $this->planSlug($storeId) : null;
        if ($plan) {
            $cap = (int) AbuseGuard::value(self::GUARD, $bucket.'_daily_'.$plan, $cap);
        }

        return max(0, $cap);
    }

    protected function planSlug(int $storeId): ?string
    {
        // Cached ~5 min: this runs on every public hit and plan() is not free.
        return Cache::remember('storecap:plan:'.$storeId, 300, function () use ($storeId) {
            try {
                $owner = Store::whereKey($storeId)->first()?->user;
                $plan = $owner?->plan();
                $slug = is_object($plan) ? ($plan->slug ?? null) : null;

                return is_string($slug) && $slug !== '' ? (string) preg_replace('/[^a-z0-9_]/', '_', strtolower($slug)) : '';
            } catch (\Throwable $e) {
                return '';
            }
        }) ?: null;
    }

    /**
     * Store from the ROUTE only (never the request body: an anonymous caller could name another store to burn or dodge
     * its cap) or the token-resolved tenant. Unresolved -> 0 (platform bucket; the per-IP sub-cap still applies).
     */
    protected function resolveStoreId(Request $request): int
    {
        foreach (['store', 'slug', 'id'] as $param) {
            $v = $request->route($param);
            if ($v instanceof Store) {
                return (int) $v->id;
            }
            if (! is_scalar($v) || (string) $v === '') {
                continue;
            }
            // Slug first (a store slug can be all digits); only {id} is a pure numeric id.
            if ($param !== 'id') {
                $id = Store::where('slug', (string) $v)->value('id');
                if ($id) {
                    return (int) $id;
                }
            }
            if (ctype_digit((string) $v) && $param !== 'slug') {
                return (int) $v;
            }
        }

        if (app()->bound('api.storefront_post') && ($store = app('api.storefront_post'))) {
            return (int) ($store->id ?? 0);
        }

        return 0;
    }
}
