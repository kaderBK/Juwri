<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use Juwri\Core\Services\AlertDispatcher;
use App\Services\PlatformMetrics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * O3 — scheduler failure alerting. A scheduled task's onFailure hook routes
 * here: it logs to the platform_alerts channel (added by O4; falls back to the
 * default channel until then) and records a scheduler.fail metric. It also
 * tracks the "last clean subscriptions:verify" date so the plans-rebuild Retire
 * gate ("N consecutive clean verify days") is observable.
 */
class SchedulerAlert
{
    public const VERIFY_CLEAN_KEY = 'subscriptions_verify_last_clean';

    /** Record + log a scheduled-task failure. Best-effort — never throws. */
    public static function fail(string $command): void
    {
        // O4: surface on the dashboard + platform_alerts channel via the shared
        // dispatcher (falls back to a plain log if the dispatcher is unavailable).
        try {
            AlertDispatcher::send('scheduler', '[scheduler-fail] '.$command);
        } catch (\Throwable $e) {
            try {
                Log::error('[scheduler-fail] '.$command);
            } catch (\Throwable $e2) {
                // give up quietly — alerting must never break the scheduler
            }
        }

        try {
            PlatformMetrics::record('scheduler.fail', $command);
        } catch (\Throwable $e) {
            // metrics table may be absent on a half-migrated box; ignore
        }
    }

    /** Stamp today as the last clean subscriptions:verify run. */
    public static function markVerifyClean(): void
    {
        try {
            Cache::forever(self::VERIFY_CLEAN_KEY, now()->toDateString());
        } catch (\Throwable $e) {
            // ignore cache failures
        }
    }

    /** Clear the clean-verify stamp when a verify run drifts. */
    public static function markVerifyFailed(): void
    {
        try {
            Cache::forget(self::VERIFY_CLEAN_KEY);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** The date of the last clean subscriptions:verify run, or null. */
    public static function lastCleanVerify(): ?string
    {
        try {
            return Cache::get(self::VERIFY_CLEAN_KEY);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Surface subscriptions:verify DRIFT from a scheduled run without logging a
     * hard scheduler failure. Drift is an expected, observable condition during
     * the dual-write window (the command still exits 1 for CI gating), so the
     * nightly scheduled run reports it here — informational, dashboard-visible —
     * instead of spamming "Scheduled command … failed with exit code [1]" and a
     * [scheduler-fail] alert every night. Best-effort — never throws.
     */
    public static function verifyDrift(string $detail): void
    {
        try {
            AlertDispatcher::send('scheduler', '[subscriptions-drift] '.$detail);
        } catch (\Throwable $e) {
            try {
                Log::warning('[subscriptions-drift] '.$detail);
            } catch (\Throwable $e2) {
                // give up quietly — alerting must never break the scheduler
            }
        }

        try {
            PlatformMetrics::record('subscriptions.drift', $detail);
        } catch (\Throwable $e) {
            // metrics table may be absent on a half-migrated box; ignore
        }
    }

    /**
     * Surface entitlements:reconcile DRIFT from a scheduled run without logging a
     * hard scheduler failure. The command is a report-only drift detector that
     * exits 1 on drift for CI gating; its weekly scheduled run would otherwise log
     * "Scheduled command … failed with exit code [1]" every week drift exists
     * (e.g. a benign missing mirror row). Reported here instead — informational,
     * dashboard-visible. Best-effort — never throws.
     */
    public static function entitlementsDrift(string $detail): void
    {
        try {
            AlertDispatcher::send('scheduler', '[entitlements-drift] '.$detail);
        } catch (\Throwable $e) {
            try {
                Log::warning('[entitlements-drift] '.$detail);
            } catch (\Throwable $e2) {
                // give up quietly — alerting must never break the scheduler
            }
        }

        try {
            PlatformMetrics::record('entitlements.drift', $detail);
        } catch (\Throwable $e) {
            // metrics table may be absent on a half-migrated box; ignore
        }
    }
}
