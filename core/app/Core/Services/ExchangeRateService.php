<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use Juwri\Core\Models\ExchangeRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * MC-30 — display-only FX. Reads the daily `exchange_rates` snapshot (filled by
 * `currency:refresh-rates`); NEVER fetches over the network on a request path.
 * A rate here converts a price for DISPLAY only — it must never reach a charge,
 * a stored total, or JSON-LD (rule 5).
 */
class ExchangeRateService
{
    public const BASE = 'USD';

    public const PROVIDER = 'frankfurter';

    private const STALE_HOURS = 72;

    private const CACHE_TTL = 3600; // 1h; the feed only changes once a day

    private const CACHE_KEY = 'fx.usd_rates.v1';

    /**
     * USD→quote rate map (includes USD→USD = 1), memoised for an hour. Reads the
     * DB snapshot only — no network.
     *
     * @return array<string, float>
     */
    private static function usdRates(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $map = ExchangeRate::where('base', self::BASE)->where('provider', self::PROVIDER)
                ->get(['quote', 'rate'])
                ->mapWithKeys(fn ($r) => [strtoupper((string) $r->quote) => (float) $r->rate])
                ->all();
            $map[self::BASE] = 1.0;

            return $map;
        });
    }

    /**
     * Display FX rate $from → $to, crossed via USD. Returns null when either code
     * is unknown, so the caller can fall back to manual/pinned rates rather than
     * guessing.
     */
    public static function rate(string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }

        $rates = self::usdRates();
        if (! isset($rates[$from], $rates[$to]) || (float) $rates[$from] === 0.0) {
            return null;
        }

        return (float) $rates[$to] / (float) $rates[$from];
    }

    /** Newest fetch time across all rates, or null if the feed has never run. */
    public static function lastFetchedAt(): ?Carbon
    {
        $ts = ExchangeRate::where('provider', self::PROVIDER)->max('fetched_at');

        return $ts ? Carbon::parse($ts) : null;
    }

    /** The feed is stale when the newest rate is older than 72h (or absent). */
    public static function isStale(): bool
    {
        $last = self::lastFetchedAt();

        return $last === null || $last->lt(Carbon::now()->subHours(self::STALE_HOURS));
    }

    /** Drop the 1h memo (the refresh command calls this after an upsert). */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
