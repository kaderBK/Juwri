<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * P1-23 part 4 — the late-payment promise of Terms 6.4 (and Reseller Agreement 7.7) as one rule:
 * services may be suspended only when an undisputed amount is MORE than 14 days overdue AND at least
 * 7 days after written notice. Used by the Cloud subscription tick, the MoR past_due webhook, the reseller wholesale
 * dunning and the paid app-install renewals (P1-23 part 7; Community, so the AppStore can use it), so all count the
 * same days. The grace comes from config('billing.past_due_grace_days') (the Billing package's config, or an operator's
 * config/billing.php); without either it is 21.
 *
 * Ladder (calendar days from the day the amount fell overdue, default grace 21):
 *   day 0 overdue → day (grace − 7) ≥ 14 written notice → day grace ≥ 21 (and ≥ notice + 7) suspension.
 * A late or failed notice pushes the suspension to notice + 7; no notice = no suspension.
 */
final class OverduePolicy
{
    /** The amount must be at least this many days overdue before the notice goes out (Terms 6.4). */
    public const OVERDUE_DAYS_BEFORE_NOTICE = 14;

    /** Written notice before any suspension (Terms 6.4). */
    public const NOTICE_DAYS = 7;

    /** 14 + 7: the shortest grace the rule allows. The config can lengthen it, never shorten it. */
    public const MIN_GRACE_DAYS = self::OVERDUE_DAYS_BEFORE_NOTICE + self::NOTICE_DAYS;

    /** Days from "overdue" to the planned suspension: config('billing.past_due_grace_days'), clamped to ≥ 21. */
    public static function graceDays(): int
    {
        return max(self::MIN_GRACE_DAYS, (int) config('billing.past_due_grace_days', self::MIN_GRACE_DAYS));
    }

    /** The planned suspension day before any notice went out. */
    public static function plannedSuspension(CarbonInterface $overdueSince): Carbon
    {
        return Carbon::instance($overdueSince)->addDays(self::graceDays());
    }

    /**
     * Earliest moment the services may be suspended: the planned day, pushed to notice + 7 days when the notice went
     * out later than planned. Without a notice there is no such moment (null).
     */
    public static function suspendOn(CarbonInterface $overdueSince, ?CarbonInterface $noticeAt): ?Carbon
    {
        if ($noticeAt === null) {
            return null;
        }
        $planned = self::plannedSuspension($overdueSince);
        $byNotice = Carbon::instance($noticeAt)->addDays(self::NOTICE_DAYS);

        return $byNotice->gt($planned) ? $byNotice : $planned;
    }

    /**
     * When the notice is due: 7 days before the planned suspension (`$plannedSuspension`, which may be later than the
     * default when an admin extended it), never before the amount is 14 days overdue.
     */
    public static function noticeDueAt(CarbonInterface $overdueSince, CarbonInterface $plannedSuspension): Carbon
    {
        $sevenBefore = Carbon::instance($plannedSuspension)->subDays(self::NOTICE_DAYS);
        $fourteenOverdue = Carbon::instance($overdueSince)->addDays(self::OVERDUE_DAYS_BEFORE_NOTICE);

        return $sevenBefore->gt($fourteenOverdue) ? $sevenBefore : $fourteenOverdue;
    }

    /** The day a notice sent at `$noticeAt` names: never sooner than 7 days later, never sooner than the plan. */
    public static function noticeSuspensionDate(CarbonInterface $plannedSuspension, CarbonInterface $noticeAt): Carbon
    {
        $byNotice = Carbon::instance($noticeAt)->addDays(self::NOTICE_DAYS);

        return $byNotice->gt($plannedSuspension) ? $byNotice : Carbon::instance($plannedSuspension);
    }

    public static function maySuspend(CarbonInterface $overdueSince, ?CarbonInterface $noticeAt, CarbonInterface $now): bool
    {
        $on = self::suspendOn($overdueSince, $noticeAt);

        return $on !== null && $now->gte($on);
    }
}
