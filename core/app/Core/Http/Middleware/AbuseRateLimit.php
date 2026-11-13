<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Juwri\Core\Services\AbuseGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Abuse Guard P2 (2.1): warn/enforce-aware named rate limiter.
 *
 * Usage: ->middleware('ratelimit:ai'). Reads per-minute / per-hour ceilings from
 * config('settings.abuse_rl_<guard>_per_min'|'_per_hour') with code defaults, keys
 * the counter per SPEC §1, and routes the would-be block through AbuseGuard::hit()
 * so WARN mode logs an abuse_event and lets the request through, while ENFORCE
 * returns 429 with Retry-After. OFF short-circuits (no counting, no logging).
 *
 * Custom (not Laravel's throttle:) because warn-mode must *log the overage yet
 * still allow* — the stock limiter can only block or not.
 */
class AbuseRateLimit
{
    /** guard => [per-min default, per-hour default]. Mirrors AbuseGuardController::LIMITERS. */
    public const DEFAULTS = [
        'ai' => [10, 100],
        'export' => [5, 30],
        'upload' => [20, 200],
        'public_write' => [10, 60],
        'signup' => [5, 15],
        'webhook_in' => [120, 2000],
        'mcp' => [60, 1000],
        // Authenticated dashboard/KDS/bell pollers. Keyed per user (resolveKey
        // default arm). A legit owner with several open tabs stays well under 60/min
        // (a 6s KDS loop is ~10/min); the ceiling is a flood backstop, not a per-tab
        // limit — the client LivePoll guard already stops a single stale tab. Ships
        // in warn mode like every guard (logs, does not block); enforce is an owner flip.
        'dashboard_poll' => [60, 1200],
    ];

    public function handle(Request $request, Closure $next, string $guard)
    {
        // hyphenated route usage (public-write) normalizes to the underscore key
        $guard = str_replace('-', '_', $guard);

        if (AbuseGuard::mode($guard) === AbuseGuard::OFF) {
            return $next($request);
        }

        // Inbound webhooks: reject oversized payloads before parsing (spec §2.4).
        if ($guard === 'webhook_in') {
            $capKb = (int) AbuseGuard::value('rl', 'webhook_in_max_kb', 64);
            if ($capKb > 0) {
                $len = (int) ($request->header('Content-Length') ?: strlen($request->getContent()));
                if ($len > $capKb * 1024) {
                    if (! AbuseGuard::hit($guard, 'payload_too_large', ['ip' => $request->ip(), 'meta' => ['bytes' => $len, 'max_kb' => $capKb]])) {
                        return response()->json(['message' => 'Payload too large.'], 413);
                    }
                }
            }
        }

        [$perMin, $perHour] = self::DEFAULTS[$guard] ?? [60, 1000];
        $perMin = (int) AbuseGuard::value('rl', $guard.'_per_min', $perMin);
        $perHour = (int) AbuseGuard::value('rl', $guard.'_per_hour', $perHour);

        $dimension = $this->resolveKey($request, $guard);

        foreach ([[60, $perMin], [3600, $perHour]] as [$decay, $max]) {
            if ($max <= 0) {
                continue;
            }
            $rlKey = 'abuse:'.$guard.':'.$decay.':'.$dimension;

            if (RateLimiter::tooManyAttempts($rlKey, $max)) {
                $ctx = [
                    'user_id' => optional($request->user())->id,
                    'ip' => $request->ip(),
                    'meta' => ['guard' => $guard, 'window' => $decay, 'max' => $max, 'key' => $dimension],
                ];

                // enforce → hit() returns false → block; warn → true → allow (logged)
                if (! AbuseGuard::hit($guard, 'rate_limit', $ctx)) {
                    $retry = RateLimiter::availableIn($rlKey);

                    return response()->json([
                        'message' => 'Too many requests. Please slow down.',
                    ], 429)->header('Retry-After', $retry);
                }
                // warn: logged and allowed; still count this attempt
            }

            RateLimiter::hit($rlKey, $decay);
        }

        return $next($request);
    }

    /**
     * Counter dimension per SPEC §1:
     *   ai            → user id (guest: ip) + reseller 2nd dimension
     *   export/upload → user id
     *   public_write  → ip
     *   signup        → ip
     *   webhook_in    → route name + source id (falls back to ip)
     *   mcp           → bearer token (guest: ip)
     */
    protected function resolveKey(Request $request, string $guard): string
    {
        $user = $request->user();
        $ip = $request->ip() ?: 'noip';

        return match ($guard) {
            'ai' => ($user ? 'u'.$user->id : 'ip'.$ip)
                .':r'.($user->reseller_id ?? '0'),
            'export', 'upload' => $user ? 'u'.$user->id : 'ip'.$ip,
            'public_write', 'signup' => 'ip'.$ip,
            'webhook_in' => ($request->route()?->getName() ?: 'wh')
                .':'.($request->route('id') ?? $request->route('source') ?? $ip),
            'mcp' => ($token = $request->bearerToken()) ? 'tok'.sha1($token) : 'ip'.$ip,
            default => $user ? 'u'.$user->id : 'ip'.$ip,
        };
    }
}
