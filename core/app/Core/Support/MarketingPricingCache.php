<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The version stamp that re-keys the marketing full-page cache (/pricing, home).
 *
 * The marketing cache stores a rendered page for 12 hours, so an admin who
 * renames a plan or changes its price kept seeing the old catalogue until the
 * TTL expired. The stamp is folded into the cache key of the pricing pages:
 * saving or deleting a plan bumps it, the next request renders a fresh body
 * under a fresh key, and the stale body is simply never read again.
 *
 * The stamp lives in the cache store itself (not in the database) so a plan
 * write never needs a migration and never fails because of one.
 */
final class MarketingPricingCache
{
    /** The cache key holding the stamp. */
    public const KEY = 'marketing:pricing-version';

    /**
     * The current stamp. A missing one (never set, or evicted by the store) is
     * seeded from the clock, so it never walks back to a stamp an older cached
     * page may still be keyed on.
     */
    public static function version(): int
    {
        try {
            $version = Cache::get(self::KEY);
            if (! is_numeric($version)) {
                Cache::add(self::KEY, self::seed());
                $version = Cache::get(self::KEY);
            }

            return is_numeric($version) ? (int) $version : 1;
        } catch (Throwable) {
            // The cache store is unavailable (a deploy mid-flight, a locked
            // Redis): fall back to the initial stamp — the key stays stable
            // and the page is still served.
            return 1;
        }
    }

    /**
     * Bump the stamp: every cached /pricing (and home) body is orphaned and the
     * next request renders the current catalogue.
     *
     * Atomic: seed the stamp once (add() is a no-op when it exists), then
     * increment it in the store — two admins saving at once never read the same
     * stamp and write the same "+1", which would lose one of the bumps. The seed
     * is the clock, not 1: when the store evicts the stamp (Redis maxmemory) a
     * reseed at 1 would walk back through stamps already used, and an old cached
     * page keyed on one of them would be served again.
     */
    public static function bump(): void
    {
        try {
            Cache::add(self::KEY, self::seed());
            Cache::increment(self::KEY);
        } catch (Throwable) {
            // The cache store can be unavailable mid-deploy; the 12h TTL of the
            // marketing cache still applies, so the page recovers on its own.
        }
    }

    /**
     * A fresh stamp: the clock in milliseconds. Bumps add 1 each, so a reseed
     * after eviction lands past every stamp used before it unless more bumps
     * than milliseconds went by.
     */
    private static function seed(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
