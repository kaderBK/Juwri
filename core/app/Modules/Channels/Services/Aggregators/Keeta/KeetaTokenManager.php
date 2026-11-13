<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Keeta;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * N2 — Keeta token lifecycle policy (pure, testable — no network).
 *
 * Keeta access tokens are valid 90 days with a refresh token. Policy:
 *  - refresh EARLY: 3–5 days before expiry (we use 4 days).
 *  - GRACE: the old access token still works for 60 min after refresh.
 *  - refresh is RATE-LIMITED to 1/min — guard so a burst never double-refreshes.
 *
 * The real client persists {access_token, refresh_token, issued_at, expires_at,
 * last_refresh_at} in the Credential vault and consults this manager before each call.
 */
class KeetaTokenManager
{
    public const REFRESH_EARLY_DAYS = 4;
    public const GRACE_MINUTES = 60;
    public const REFRESH_MIN_INTERVAL_SECONDS = 60;

    /** Should we refresh now? True once within the early window (or already expired). */
    public static function shouldRefresh(?CarbonInterface $expiresAt, ?CarbonInterface $now = null): bool
    {
        if ($expiresAt === null) {
            return true; // no token yet
        }
        $now = $now ?: Carbon::now();
        return $now->gte($expiresAt->copy()->subDays(self::REFRESH_EARLY_DAYS));
    }

    /** Rate guard: may we issue a refresh, given the last refresh time? (1/min). */
    public static function canRefresh(?CarbonInterface $lastRefreshAt, ?CarbonInterface $now = null): bool
    {
        if ($lastRefreshAt === null) {
            return true;
        }
        $now = $now ?: Carbon::now();
        return abs($now->diffInSeconds($lastRefreshAt)) >= self::REFRESH_MIN_INTERVAL_SECONDS;
    }

    /** Is the old token still usable under the 60-min grace after a refresh? */
    public static function withinGrace(?CarbonInterface $refreshedAt, ?CarbonInterface $now = null): bool
    {
        if ($refreshedAt === null) {
            return false;
        }
        $now = $now ?: Carbon::now();
        return $now->lte($refreshedAt->copy()->addMinutes(self::GRACE_MINUTES));
    }
}
