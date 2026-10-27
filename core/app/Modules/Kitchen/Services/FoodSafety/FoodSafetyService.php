<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Kitchen\Services\FoodSafety;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Juwri\Modules\Kitchen\Models\FoodSafetyCheck;
use Juwri\Modules\Kitchen\Models\FoodSafetyLog;

/**
 * GAPS-RUN-2 B10-P1: food-safety operations — due checks for the day, log a reading
 * (out-of-range temps flag a breach), record incidents, and compute a compliance rate.
 */
class FoodSafetyService
{
    /** Active checks not yet logged today (completion state per calendar date). */
    public function dueToday(int $storeId, ?Carbon $date = null): Collection
    {
        $date = ($date ?: now())->copy();
        $loggedCheckIds = FoodSafetyLog::where('store_id', $storeId)
            ->whereNotNull('check_id')
            ->whereBetween('logged_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->pluck('check_id')
            ->unique();

        return FoodSafetyCheck::where('store_id', $storeId)
            ->where('active', true)
            ->whereNotIn('id', $loggedCheckIds)
            ->orderBy('position')
            ->get();
    }

    /** Log a check reading. Out-of-range temps set breach=true. */
    public function log(FoodSafetyCheck $check, ?int $staffId, ?float $value, ?string $note = null): FoodSafetyLog
    {
        return FoodSafetyLog::create([
            'check_id' => $check->id,
            'store_id' => $check->store_id,
            'branch_id' => $check->branch_id,
            'staff_id' => $staffId,
            'kind' => $check->kind,
            'value' => $value,
            'breach' => $check->isBreach($value),
            'note' => $note,
            'logged_at' => now(),
        ]);
    }

    /** Record a free-form incident (note required by the caller/validation). */
    public function logIncident(int $storeId, ?int $staffId, string $note, ?int $branchId = null): FoodSafetyLog
    {
        return FoodSafetyLog::create([
            'check_id' => null,
            'store_id' => $storeId,
            'branch_id' => $branchId,
            'staff_id' => $staffId,
            'kind' => 'incident',
            'breach' => true, // incidents always surface
            'note' => $note,
            'logged_at' => now(),
        ]);
    }

    /**
     * Compliance rate over the trailing N days: completed check-days ÷ expected
     * check-days (active daily checks × days). Returns 0.0–1.0.
     */
    public function complianceRate(int $storeId, int $days = 30): float
    {
        $checks = FoodSafetyCheck::where('store_id', $storeId)
            ->where('active', true)->get(['id', 'created_at']);
        if ($checks->isEmpty()) {
            return 1.0;
        }
        $windowStart = now()->copy()->subDays($days)->startOfDay();

        // Expected = Σ per-check days it has EXISTED within the window (a check added
        // yesterday only owes 1 day, not the full window) — capped at $days.
        $expected = 0;
        foreach ($checks as $c) {
            $activeSince = $c->created_at && $c->created_at->gt($windowStart) ? $c->created_at : $windowStart;
            $expected += min($days, max(1, (int) ceil($activeSince->diffInDays(now()->endOfDay()))));
        }

        $from = $windowStart;
        // Distinct (check_id, day) completions in the window.
        $completed = FoodSafetyLog::where('store_id', $storeId)
            ->whereNotNull('check_id')
            ->where('logged_at', '>=', $from)
            ->get(['check_id', 'logged_at'])
            ->map(fn ($l) => $l->check_id.':'.Carbon::parse($l->logged_at)->toDateString())
            ->unique()
            ->count();

        return round(min(1.0, $completed / max(1, $expected)), 4);
    }
}
