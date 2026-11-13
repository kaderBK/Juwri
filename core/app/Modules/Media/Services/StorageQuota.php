<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Services;

use Juwri\Core\Contracts\Billing\PlanLimits;
use Juwri\Core\Models\AccountUsage;
use Juwri\Core\Models\User;
use Juwri\Core\Services\AbuseGuard;

/**
 * Abuse Guard P5 (5.4): per-tenant storage byte counter.
 *
 * Counts uploaded bytes per user id in `account_usage` (metric `storage_bytes`; P1-05 moved it off the
 * dynamic platform setting `abuse_storage_bytes_<id>`). Uploads
 * over the tenant cap route through AbuseGuard: WARN logs+allows, ENFORCE
 * denies. Cap read from config('settings.abuse_storage_tenant_cap_mb').
 */
class StorageQuota
{
    /** account_usage metric holding the running byte total for a user. */
    public const METRIC = 'storage_bytes';

    /** Current bytes used by a user (0 default). */
    public static function used(int $userId): int
    {
        return AccountUsage::value($userId, self::METRIC);
    }

    /** Add uploaded bytes to a user's running total. */
    public static function add(int $userId, int $bytes): void
    {
        AccountUsage::adjust($userId, self::METRIC, max(0, $bytes));
    }

    /** Subtract removed bytes, clamped at zero. */
    public static function sub(int $userId, int $bytes): void
    {
        AccountUsage::adjust($userId, self::METRIC, -max(0, $bytes));
    }

    /** Set the absolute running total (backfill). */
    public static function put(int $userId, int $bytes): void
    {
        AccountUsage::put($userId, self::METRIC, max(0, $bytes));
    }

    /** @return array<int, int> user id => bytes */
    public static function all(): array
    {
        return AccountUsage::allFor(self::METRIC);
    }

    /** Effectively-unlimited storage cap (MB) for the 999 plan sentinel. */
    public const UNLIMITED_MB = 1_000_000;

    /**
     * Tenant storage cap in megabytes. E3: when a user id is given and their plan
     * sets `storage_mb`, that value wins (999 sentinel = unlimited); otherwise the
     * platform default (500) via AbuseGuard — exactly today's behavior.
     */
    public static function capMb(?int $userId = null): int
    {
        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $raw = app(PlanLimits::class)->value($user, 'storage_mb');
                if ($raw !== null && $raw !== '') {
                    $n = (int) $raw;

                    return $n >= 999 ? self::UNLIMITED_MB : max(0, $n);
                }
            }
        }

        return (int) AbuseGuard::value('storage', 'tenant_cap_mb', 500);
    }

    /**
     * Whether an incoming upload is allowed. Under cap → true. Over cap →
     * AbuseGuard::hit semantics (warn logs+allows, enforce denies).
     */
    public static function allow(int $userId, int $incomingBytes): bool
    {
        $capBytes = self::capMb($userId) * 1024 * 1024;

        if (self::used($userId) + $incomingBytes <= $capBytes) {
            return true;
        }

        return AbuseGuard::hit('storage', 'over_quota', ['user_id' => $userId]);
    }
}
