<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Storefront;

use Illuminate\Support\Facades\Cache;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P5 — caches the assembled storefront menu payload (the base Menu::with(...) load +
 * global-modifier merge) per restaurant/branch/locale. Time-sensitive steps (branch
 * price overrides, happy-hour decorate) run on the deserialized copy each request, so
 * they are never frozen into the cache.
 *
 * Busting uses a per-store random token folded into the key, so any cache driver
 * (file included — cache tags are not required) invalidates atomically on a menu write.
 */
class StorefrontMenuCache
{
    /**
     * Remember the assembled payload. TTL 0 (config perf.menu_cache_ttl) disables the
     * cache — the callback runs every request.
     *
     * @template T
     *
     * @param  \Closure():T  $callback
     * @return T
     */
    public static function remember(int $storeId, ?int $branchId, string $locale, \Closure $callback)
    {
        $ttl = (int) config('perf.menu_cache_ttl', 300);
        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::flexible(self::key($storeId, $branchId, $locale), [$ttl, $ttl * 2], $callback);
    }

    public static function key(int $storeId, ?int $branchId, string $locale): string
    {
        return 'menu.payload:'.$storeId
            .':v'.self::version($storeId)
            .':b'.((int) $branchId)
            .':'.$locale;
    }

    /** Cache key that holds a store's menu-cache token. */
    private static function versionKey(int $storeId): string
    {
        return 'menu.ver:'.$storeId;
    }

    /**
     * Current menu-cache token for a store (replaced on any menu write).
     *
     * M2: this token lives in the same cache store as the payloads it guards, and
     * under a memory-pressure policy such as redis `allkeys-lru` the tiny token is
     * evicted without regard to size — long before the large payloads. The old code
     * fell back to the constant `1`, which REBUILT a key the store had already used,
     * so a payload still inside its TTL was served: the store's pre-edit menu at
     * pre-edit prices, with signature() reverting in lockstep so browsers 304'd the
     * stale body too.
     *
     * A missing token is therefore a cache MISS, never a revert. It is a random
     * token rather than a counter precisely so that no eviction, clock skew or burst
     * of writes can ever reproduce a value that already keyed a payload.
     */
    public static function version(int $storeId): string
    {
        return self::peek($storeId) ?? self::mint($storeId);
    }

    /**
     * Raw read of the token — never mints. For diagnostic/operator surfaces only (the
     * superadmin Menu Cache panel), so that merely LOOKING at the state cannot change it.
     */
    public static function peek(int $storeId): ?string
    {
        $token = Cache::get(self::versionKey($storeId));

        return $token === null ? null : (string) $token;
    }

    /** Mint and persist a brand-new token that has never keyed a payload. */
    private static function mint(int $storeId): string
    {
        $token = bin2hex(random_bytes(6));
        // forever, not a 30-day TTL: an expiring token meant a store that went a month
        // without a menu edit silently lost its token — the same stale-serve and
        // ETag-revert, on a timer instead of under memory pressure. NB `forever`
        // does NOT make the key un-evictable under `allkeys-lru`; it removes the
        // second, independent bug. The MISS-on-missing behaviour above is what
        // makes eviction safe.
        Cache::forever(self::versionKey($storeId), $token);

        return $token;
    }

    /**
     * Invalidate a store's cached menu payloads by replacing its token. Old keys become
     * unreachable and expire on their own TTL. Safe on every cache driver.
     */
    public static function bust(?int $storeId): void
    {
        if (! $storeId) {
            return;
        }
        // Mint outright rather than read-modify-write a counter: two concurrent menu
        // writes both produce a fresh, unused token, so neither can lose the race and
        // leave the pre-edit payload reachable.
        self::mint($storeId);
    }

    /**
     * ETag signature for the store's menu (P5). The per-store token (replaced on
     * every menu write via BustsMenuCache) is the change signal; max(id) is a cheap,
     * MySQL-safe secondary signal for add/delete. NB: the `menu` table has no
     * `updated_at` column in prod — do not query it here (sqlite-vs-MySQL gap).
     */
    public static function signature(int $storeId, ?int $branchId, string $locale): string
    {
        $maxId = (int) Menu::where('store_id', $storeId)->max('id');

        return md5($storeId.'|'.((int) $branchId).'|'.$locale.'|'.$maxId.'|v'.self::version($storeId));
    }
}
