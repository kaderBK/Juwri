<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Juwri\Core\Contracts\Billing\EntitlementSource;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Listeners\BroadcastEntitlementChange;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\RequestEpoch;

/**
 * FIX-2 (feature-platform gap audit): the serve-time twin of the `feature:<key>`
 * middleware, for the entry points a middleware cannot cover — services,
 * storefront Blade, and staff/customer transports where the request has no
 * dashboard owner.
 *
 * Same two-lane contract as EnsureFeatureEnabled, in the same order:
 *   1. kill-switch veto ALWAYS (platform OFF beats every commercial grant);
 *   2. entitlement check ONLY when the key is `plan_gated` — ungated keys keep
 *      today's kill-only behavior byte for byte.
 *
 * The subject matters. On a storefront request the caller is an anonymous
 * shopper, so `dashboard_owner()` is null and an owner-scoped check is the only
 * correct one: it is the STORE OWNER who bought (or did not buy) the SKU.
 * Attaching `feature:<key>` middleware to a public route instead 403s every
 * visitor — that dead end is recorded in FIX-1.
 */
class FeatureAccess
{
    /** Per-request memo, keyed "storeId:key" — storefronts hit this per render. */
    private static array $storeMemo = [];

    /** The application instance the memos here were filled for. */
    private static ?int $memoApp = null;

    /**
     * Bind the memos to the current application instance, as StoreOption does for
     * its option memo.
     *
     * Both are keyed by a database id (store, user) plus a feature key, and an id
     * says nothing about WHICH database it came from. Under the test runner that
     * is a cross-class leak: RefreshDatabase reuses the same low ids, so an
     * entitlement resolved for one test's owner would answer for the unrelated
     * owner that inherited the id in the next.
     */
    private static function scopeMemo(): void
    {
        $id = RequestEpoch::id();
        if (self::$memoApp !== $id) {
            self::$memoApp = $id;
            self::$storeMemo = [];
            self::$sourceMemo = [];
        }
    }

    /** Kill veto + (when plan_gated) this user's entitlement. Null user = refused on gated keys. */
    public static function allows(?User $user, string $key): bool
    {
        if (! FeatureRegistry::isEnabled($key)) {
            return false;
        }

        if (! EnsureFeatureEnabled::planGated($key)) {
            return true;
        }

        return $user instanceof User && Entitlements::allows($user, $key);
    }

    /**
     * Subject = the store's OWNER. Use on every anonymous/storefront/staff path
     * (public POSTs, theme partials, staff PWA) where the buyer is the merchant,
     * not the requester.
     */
    public static function storeAllows(?int $storeId, string $key): bool
    {
        if (! FeatureRegistry::isEnabled($key)) {
            return false;
        }

        if (! EnsureFeatureEnabled::planGated($key)) {
            return true;
        }

        if (! $storeId) {
            return false;
        }

        self::scopeMemo();

        $memoKey = $storeId.':'.$key;
        if (array_key_exists($memoKey, self::$storeMemo)) {
            return self::$storeMemo[$memoKey];
        }

        $ownerId = (int) Store::where('id', $storeId)->value('user_id');
        $owner = $ownerId ? User::find($ownerId) : null;

        return self::$storeMemo[$memoKey] = self::allows($owner, $key);
    }

    /** M0-2: per-request memo of sources(), keyed "userId:key". */
    private static array $sourceMemo = [];

    /**
     * M0-2 (monetization): every reason this user has the key, not just whether.
     *
     * Built ON TOP of the existing resolver — {@see Entitlements::planTrace()}
     * for the plan chain, the same override and add-on queries {@see
     * Entitlements::has()} runs, and {@see Entitlements::bundleGrants()} for
     * bundles. It is a second VIEW of one resolution, never a second resolver:
     * the run's review found the "grants are computed in several places"
     * premise to be wrong, and a parallel implementation is exactly how that
     * would become true.
     *
     * Spotlight's `feature_grants` table is deliberately NOT an input. That
     * table is a best-effort display cache (create-once, error-swallowing), so
     * reading it here would make entitlement answers depend on whether a cache
     * write happened to succeed.
     *
     * Returns `[]` when nothing grants — including when the kill-switch vetoes,
     * because a platform-wide OFF means the merchant does not have it today
     * whatever they paid. `channel` collapses the three plan lanes for callers
     * that only need "plan vs bought"; `source` keeps the lane for callers that
     * need to explain or price it.
     *
     * @return array<int, array{source:string,channel:string,label:string,detail:?string,ref:?string,expires_at:?string}>
     */
    public static function sources(?User $user, string $key): array
    {
        if (! $user instanceof User) {
            return [];
        }

        self::scopeMemo();

        $memoKey = $user->id.':'.$key;
        if (array_key_exists($memoKey, self::$sourceMemo)) {
            return self::$sourceMemo[$memoKey];
        }

        return self::$sourceMemo[$memoKey] = self::resolveSources($user, $key);
    }

    /** Subject rule of storeAllows(): the buyer is the STORE OWNER. */
    public static function storeSources(?int $storeId, string $key): array
    {
        if (! $storeId) {
            return [];
        }

        $ownerId = (int) Store::where('id', $storeId)->value('user_id');

        return self::sources($ownerId ? User::find($ownerId) : null, $key);
    }

    private static function resolveSources(User $user, string $key): array
    {
        // Kill-switch and ban are vetoes, not sources: nothing grants today.
        if (! FeatureRegistry::isEnabled($key) || Entitlements::isBanned($user)) {
            return [];
        }

        $sources = [];

        /* The dark lane. While a key is `plan_gated = 0` the `feature:`
           middleware is a kill-switch only, so EVERY store has it regardless of
           what they bought — and saying so is the honest answer to "why do I
           have this". It is also what makes the V0 flip legible: the keys whose
           only source is `ungated` are precisely the ones that will start
           denying on flip day. */
        if (! EnsureFeatureEnabled::planGated($key)) {
            $sources[] = self::source('ungated', 'ungated', 'Available to everyone',
                ___('Not sold separately yet.'));
        }

        $trace = Entitlements::planTrace($user, $key);
        if ($trace && (string) ($trace['value'] ?? '') === '1') {
            $sources[] = self::source($trace['lane'], 'plan', 'Included in your plan',
                $trace['lane'] === 'plan_snapshot'
                    ? ___('Locked in when you subscribed — later plan edits cannot take it away.')
                    : ___('Granted by your current plan.'));
        }

        try {
            $overrides = EntitlementOverride::query()
                ->where('user_id', $user->id)->where('feature_key', $key)
                ->active()->where('value', '1')->get();
            foreach ($overrides as $override) {
                $sources[] = self::source('override', 'override', 'Granted directly', $override->source_type, (string) $override->id, $override->expires_at?->toIso8601String());
            }
        } catch (\Throwable $e) {
            // table absent mid-migration — the resolver fails closed here too
        }

        // Purchases (boolean add-ons, bundles) — P1-13: from the EntitlementSource (Billing, ee; none in Community).
        foreach (app(EntitlementSource::class)->purchases($user, $key) as $p) {
            $sources[] = self::source($p['source'], $p['kind'], $p['label'], $p['detail'], $p['ref'], $p['expires_at']);
        }

        return $sources;
    }

    /**
     * Would this user still hold the key if one source went away? Drives the
     * M0-3 cancel rule — cancelling a bundle re-locks only the features nothing
     * else holds — and the honest "you will keep X" line at cancel time.
     */
    public static function heldElsewhere(?User $user, string $key, string $excludingSource, ?string $excludingRef = null): bool
    {
        foreach (self::sources($user, $key) as $source) {
            if ($source['source'] === $excludingSource
                && ($excludingRef === null || $source['ref'] === $excludingRef)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * `$label` is merchant-facing chrome, so it goes through ___(). `$detail`
     * is passed in already-resolved because it is sometimes chrome and
     * sometimes DATA (a SKU, a bundle's admin-authored label) — and catalog
     * content must never be registered as a translation key.
     */
    private static function source(string $source, string $channel, string $label, ?string $detail = null, ?string $ref = null, ?string $expiresAt = null): array
    {
        return [
            'source' => $source,
            'channel' => $channel,
            'label' => ___($label),
            'detail' => $detail,
            'ref' => $ref,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * F6-4: serve-time twin for HOT anonymous transports (kiosk web mode, the
     * ESC/POS print API, signage boards + their polling `state` endpoint). These
     * are hit continuously by unattended devices, so the entitlement resolution
     * is cached for {@see self::SERVE_TTL} seconds.
     *
     * The window is deliberately small AND actively busted: the F6-3 listener on
     * FeatureGranted/FeatureRevoked calls {@see self::bustUser()}, so a revoke
     * stops serving on the very next request rather than up to a minute later.
     * The TTL is the backstop for lapses that fire no event (a plan expiring at
     * its period end, a kill-switch flip in the settings table).
     *
     * Same subject rule as storeAllows(): the buyer is the STORE OWNER.
     */
    public const SERVE_TTL = 60;

    public static function storeAllowsCached(?int $storeId, string $key): bool
    {
        if (! $storeId) {
            return self::storeAllows($storeId, $key);
        }

        // Kill-switch reads config/settings, which is already in memory — check it
        // outside the cache so an admin kill takes effect without waiting for TTL.
        if (! FeatureRegistry::isEnabled($key)) {
            return false;
        }

        if (! EnsureFeatureEnabled::planGated($key)) {
            return true;
        }

        try {
            return (bool) Cache::remember(
                self::serveCacheKey($storeId, $key),
                self::SERVE_TTL,
                fn () => self::storeAllows($storeId, $key)
            );
        } catch (\Throwable $e) {
            // A broken cache store must never take a paying store's kiosk down.
            report($e);

            return self::storeAllows($storeId, $key);
        }
    }

    /**
     * Invalidate the serve-time decision for every store this owner has. Called
     * by {@see BroadcastEntitlementChange} on both entitlement
     * events; `$key` null busts every key the owner could be serving.
     */
    public static function bustUser(int $userId, ?string $key = null): void
    {
        self::flush();

        $keys = $key !== null ? [$key] : array_keys(FeatureRegistry::keyed());
        $storeIds = Store::where('user_id', $userId)->pluck('id');

        foreach ($storeIds as $storeId) {
            foreach ($keys as $k) {
                Cache::forget(self::serveCacheKey((int) $storeId, $k));
            }
        }
    }

    private static function serveCacheKey(int $storeId, string $key): string
    {
        return 'feature_access:serve:'.$storeId.':'.$key;
    }

    /** Test/queue hygiene: drop every per-request memo this class and the resolver hold. */
    public static function flush(): void
    {
        self::$storeMemo = [];
        self::$sourceMemo = [];
        Entitlements::flushBundleMemo();
    }
}
