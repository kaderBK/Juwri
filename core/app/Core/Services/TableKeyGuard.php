<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use Illuminate\Support\Facades\RateLimiter;
use Juwri\Core\Support\ClientNetwork;

/**
 * Brute-force guard for the public QR table / room key lookups that reveal something: the storefront page's
 * `?table=` (table context, room-service and charge-to-room flows) and the hotel guest-info page (Wi-Fi, room).
 * Printed keys cannot be rotated and some are short or time-derived, so a client naming keys a store does not have is
 * cut off; once blocked, every key lookup for that store answers "unknown" without touching the database (no oracle).
 *
 * Two buckets per store, both per minute:
 * - the visitor's session (when one exists): config abuse.table_key_misses_per_minute (default 20), so one guest who
 *   mistypes or scans a stale code is limited on their own;
 * - the client network (IPv4 address, IPv6 /64, see {@see ClientNetwork}): abuse.table_key_misses_per_minute_network
 *   (default 100), which catches a cookie-less script without blocking a whole venue's shared Wi-Fi at the first
 *   few wrong scans.
 *
 * Not used on the order forms: those also accept a plain table number / room id, so the key is not secret there and
 * refusing a real guest's order would cost more than it protects. Counters live in the default cache store; a
 * multi-node install needs a shared store (redis/database), or each node counts on its own.
 */
final class TableKeyGuard
{
    private const DECAY_SECONDS = 60;

    public static function sessionLimit(): int
    {
        return max(1, (int) config('abuse.table_key_misses_per_minute', 20));
    }

    public static function networkLimit(): int
    {
        return max(1, (int) config('abuse.table_key_misses_per_minute_network', 100));
    }

    /** True when this visitor (session) or their network has used up its misses for this store. */
    public static function blocked(int $storeId): bool
    {
        $session = self::sessionKey($storeId);

        return RateLimiter::tooManyAttempts(self::networkKey($storeId), self::networkLimit())
            || ($session !== null && RateLimiter::tooManyAttempts($session, self::sessionLimit()));
    }

    /**
     * Record one lookup of a key the store does not have (or a malformed key). Counted at most once per request and
     * store, since one page render may resolve the same key in more than one place (core page + kit theme).
     */
    public static function miss(int $storeId): void
    {
        $flag = 'table_key_guard.missed.'.$storeId;
        if (request()->attributes->get($flag) === true) {
            return;
        }
        request()->attributes->set($flag, true);

        RateLimiter::hit(self::networkKey($storeId), self::DECAY_SECONDS);
        if (($session = self::sessionKey($storeId)) !== null) {
            RateLimiter::hit($session, self::DECAY_SECONDS);
        }
    }

    public static function clear(int $storeId): void
    {
        RateLimiter::clear(self::networkKey($storeId));
        if (($session = self::sessionKey($storeId)) !== null) {
            RateLimiter::clear($session);
        }
    }

    private static function networkKey(int $storeId): string
    {
        return 'table-key-miss|net|'.$storeId.'|'.ClientNetwork::bucket();
    }

    private static function sessionKey(int $storeId): ?string
    {
        $request = request();
        if (! $request->hasSession()) {
            return null;
        }
        $id = (string) $request->session()->getId();

        return $id === '' ? null : 'table-key-miss|ses|'.$storeId.'|'.$id;
    }
}
