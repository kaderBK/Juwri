<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting\Gl;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SYSTEMS-RUN S0: the period-close flag. A restaurant may lock all periods up to
 * and including a YYYY-MM month; JournalEngine then refuses to post into a locked
 * month. The full close/reopen UX lands in A-P3 — this ships the guard it uses.
 */
class PeriodLock
{
    /** The latest locked period (YYYY-MM) for a restaurant, or null. */
    public static function latest(int $storeId): ?string
    {
        return DB::table('accounting_period_locks')
            ->where('store_id', $storeId)
            ->max('period');
    }

    /** Is the given date inside a closed period? */
    public static function isLocked(int $storeId, $date): bool
    {
        $latest = self::latest($storeId);
        if (! $latest) {
            return false;
        }
        $period = Carbon::parse($date)->format('Y-m');

        return $period <= $latest;
    }

    /** Lock every period up to and including $period (YYYY-MM). Idempotent. */
    public static function lock(int $storeId, string $period, ?int $lockedBy = null): void
    {
        DB::table('accounting_period_locks')->updateOrInsert(
            ['store_id' => $storeId, 'period' => $period],
            ['locked_by' => $lockedBy, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /**
     * Reopen $period (YYYY-MM). P6-274: a lock covers every month up to the latest locked one ({@see isLocked()}), so
     * reopening a month also reopens every later locked month — deleting only that month's row left it locked.
     * Returns the reopened locks (period, locked_by), oldest first, for the audit log.
     *
     * @return list<array{period: string, locked_by: int|null}>
     */
    public static function unlock(int $storeId, string $period): array
    {
        $q = DB::table('accounting_period_locks')->where('store_id', $storeId)->where('period', '>=', $period);
        $rows = (clone $q)->orderBy('period')->get(['period', 'locked_by'])
            ->map(fn ($r) => ['period' => (string) $r->period, 'locked_by' => $r->locked_by !== null ? (int) $r->locked_by : null])->all();
        $q->delete();

        return array_values($rows);
    }
}
