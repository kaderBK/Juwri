<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Services\Rota;

use App\Models\Order;
use App\Models\StaffMember;
use App\Support\StoreClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Hr\Models\StaffShift;
use Juwri\Modules\Hr\Models\StaffTimeClock;

/**
 * GAPS-RUN-2 B7-P3: payroll REPORT — a read-only aggregation. Scheduled hours
 * (rota) vs worked hours (time clock), pay = worked × hourly_rate, tips from
 * orders.served_by_staff_id. No money movement whatsoever — payments stay outside.
 */
class PayrollService
{
    /**
     * @return array{
     *   from:string, to:string,
     *   rows: array<int, array{staff_id:int,name:string,role:?string,scheduled_hours:float,worked_hours:float,worked_hours_by_week:array<string,float>,open_clock_pairs:int,rate:float,pay:float,tips:float}>
     * }
     */
    public function report(int $storeId, string $from, string $to): array
    {
        [$start, $end] = StoreClock::rangeWindow($storeId, $from, $to);

        $staff = StaffMember::where('store_id', $storeId)->orderBy('name')->get();
        $rows = [];
        // P1-05: money HALF_UP to the store currency's exponent (PHP-version independent); hours stay at 2 dp.
        $exponent = Currencies::exponent(store_currency($storeId));

        foreach ($staff as $member) {
            $scheduled = $this->scheduledHours($member->id, $start, $end);
            [$worked, $open, $byWeek] = $this->workedHours($member->id, $start, $end);
            $rate = (float) ($member->hourly_rate ?? 0);
            $tips = $this->tips($member->id, $storeId, $start, $end);

            $rows[] = [
                'staff_id' => (int) $member->id,
                'name' => $member->name,
                'role' => $member->role,
                'scheduled_hours' => round($scheduled, 2),
                'worked_hours' => round($worked, 2),
                // Per-ISO-week worked hours, so the payroll engine can apply the
                // overtime threshold weekly instead of on the period aggregate.
                'worked_hours_by_week' => $byWeek,
                'open_clock_pairs' => $open,
                'rate' => Money::round($rate, $exponent),
                'pay' => Money::round((string) Money::decimal($worked)->multipliedBy(Money::decimal($rate)), $exponent),
                'tips' => Money::round($tips, $exponent),
            ];
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    private function scheduledHours(int $staffId, ?Carbon $start, ?Carbon $end): float
    {
        $hours = 0.0;
        $query = StaffShift::where('staff_id', $staffId);
        if ($start) {
            $query->where('starts_at', '>=', $start);
        }
        if ($end) {
            $query->where('starts_at', '<', $end);
        }
        $query->get(['starts_at', 'ends_at'])
            ->each(function ($s) use (&$hours) {
                $hours += abs($s->ends_at->floatDiffInHours($s->starts_at));
            });

        return $hours;
    }

    /** @return array{0:float,1:int,2:array<string,float>} [worked hours, open (unfinished) clock pairs, hours per ISO week] */
    private function workedHours(int $staffId, ?Carbon $start, ?Carbon $end): array
    {
        $worked = 0.0;
        $open = 0;
        $byWeek = [];

        $query = StaffTimeClock::where('staff_id', $staffId);
        if ($start) {
            $query->where('clock_in_at', '>=', $start);
        }
        if ($end) {
            $query->where('clock_in_at', '<', $end);
        }
        $query->get(['clock_in_at', 'clock_out_at'])
            ->each(function ($c) use (&$worked, &$open, &$byWeek) {
                if ($c->clock_out_at === null) {
                    $open++; // still clocked in — not counted, flagged instead

                    return;
                }
                // floatDiffInHours is absolute, so an overnight shift is correct.
                $hours = abs($c->clock_out_at->floatDiffInHours($c->clock_in_at));
                $worked += $hours;
                // Bucket by the ISO year-week of clock-in (same wall-clock the sum
                // above uses), so an overnight shift lands in one week, not split.
                $week = $c->clock_in_at->format('o-W');
                $byWeek[$week] = ($byWeek[$week] ?? 0.0) + $hours;
            });

        return [$worked, $open, $byWeek];
    }

    private function tips(int $staffId, int $storeId, ?Carbon $start, ?Carbon $end): float
    {
        $query = Order::where('store_id', $storeId)
            ->where('served_by_staff_id', $staffId)
            ->where('is_paid', 1); // only real money — tips on unpaid/cancelled orders are not owed (mirrors TipPoolService::poolTotal)
        if ($start) {
            $query->where('created_at', '>=', $start);
        }
        if ($end) {
            $query->where('created_at', '<', $end);
        }

        return (float) $query->sum(DB::raw(Order::minorMoneySql('tip_amount')));
    }
}
