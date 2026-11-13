<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Juwri\Core\Services\AbuseGuard;

/**
 * Abuse Guard P5 (5.2): per-tenant pending heavy-job quota. Before dispatching a
 * heavy job, allow() checks the tenant's in-flight count against the cap and routes
 * the would-be block through AbuseGuard::hit('queue', …) — WARN logs+allows,
 * ENFORCE returns false so the caller skips the dispatch.
 *
 * Counting is a self-expiring cache counter (TTL = the longest heavy timeout): each
 * accepted dispatch bumps it and naturally decays, so a stuck worker can never wedge
 * the counter permanently. release() lets a finished job decrement early.
 */
class QueueGuard
{
    public const GUARD = 'queue';
    protected const TTL = 1800; // seconds — matches the longest heavy job timeout

    protected static function key(int $userId): string
    {
        return 'abuse:queue:pending:' . $userId;
    }

    /** True = the dispatch may proceed. Increments the pending counter when allowed. */
    public static function allow(?int $userId): bool
    {
        if (!$userId || AbuseGuard::mode(self::GUARD) === AbuseGuard::OFF) {
            return true;
        }

        $cap = (int) AbuseGuard::value(self::GUARD, 'tenant_pending_cap', 10);
        $pending = (int) Cache::get(self::key($userId), 0);

        if ($cap > 0 && $pending >= $cap) {
            $allow = AbuseGuard::hit(self::GUARD, 'pending_cap', [
                'user_id' => $userId,
                'meta'    => ['pending' => $pending, 'cap' => $cap],
            ]);
            if (!$allow) {
                return false;
            }
        }

        Cache::put(self::key($userId), $pending + 1, self::TTL);

        return true;
    }

    /** Decrement the pending counter when a heavy job finishes (best-effort). */
    public static function release(?int $userId): void
    {
        if (!$userId) {
            return;
        }
        $pending = (int) Cache::get(self::key($userId), 0);
        if ($pending > 0) {
            Cache::put(self::key($userId), $pending - 1, self::TTL);
        }
    }
}
