<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Services\Payroll;

use App\Models\StaffMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Hr\Models\LeaveRequest;
use Juwri\Modules\Hr\Models\LeaveType;
use Juwri\Modules\Hr\Models\PayrollItem;
use Juwri\Modules\Hr\Models\PayrollRun;
use Juwri\Modules\Hr\Models\StaffRoleRate;
use Juwri\Modules\Hr\Services\Rota\PayrollService;

/**
 * SYSTEMS-RUN P-P1: the payroll engine. Builds a run from worked hours
 * (PayrollService), applies overtime (hours over the weekly threshold ×
 * multiplier), paid-leave hours (H-P2 approved leave in period at base rate),
 * and manual adjustments. Approval locks recompute.
 */
class PayrollEngine
{
    public function __construct(private PayrollService $report = new PayrollService) {}

    private function weeklyThreshold(int $storeId): float
    {
        return (float) (store_options($storeId, 'overtime_weekly_threshold', 48) ?: 48);
    }

    private function overtimeMultiplier(int $storeId): float
    {
        return (float) (store_options($storeId, 'overtime_multiplier', 1.5) ?: 1.5);
    }

    private function leaveDayHours(int $storeId): float
    {
        return (float) (store_options($storeId, 'standard_day_hours', 8) ?: 8);
    }

    /** Build (or rebuild) a draft run for the period. Refuses if a locked run overlaps. */
    public function build(int $storeId, string $from, string $to): PayrollRun
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $run = PayrollRun::firstOrNew([
            'store_id' => $storeId,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ]);
        if ($run->exists && $run->isLocked()) {
            throw ValidationException::withMessages(['run' => ___('This period is locked.')]);
        }
        $run->status = 'draft';
        $run->save();
        $run->items()->delete();

        $report = $this->report->report($storeId, $start->toDateString(), $end->toDateString());
        $weeklyThreshold = $this->weeklyThreshold($storeId);
        $multiplier = $this->overtimeMultiplier($storeId);
        $dayHours = $this->leaveDayHours($storeId);

        // P1-05: pay in minor units of the run's currency — each amount HALF_UP to the currency exponent with
        // exact decimal arithmetic (PHP-version independent); net and the run total are exact integer sums.
        $currency = $run->minorMoneyCurrency();
        $total = 0;
        foreach ($report['rows'] as $row) {
            $rate = $this->rateFor($row['staff_id'], $row['role'], (float) $row['rate']);
            $worked = (float) $row['worked_hours'];

            /* Overtime is a WEEKLY rule (hours over the threshold IN A GIVEN WEEK).
               The old code summed the whole period and compared it to
               weeklyThreshold × weeks, which let a heavy week net against a light
               one — 60h in week 1 + 30h in week 2 vs a 96h combined threshold gave
               0 overtime, losing the 12h owed for the heavy week. Sum the per-week
               excess instead, using the per-week buckets PayrollService now returns
               (falls back to the period total as a single week if absent). */
            $byWeek = (array) $row['worked_hours_by_week'];
            if (empty($byWeek) && $worked > 0) {
                $byWeek = ['_' => $worked];
            }
            $overtimeHours = 0.0;
            foreach ($byWeek as $weekHours) {
                $overtimeHours += max(0.0, (float) $weekHours - $weeklyThreshold);
            }
            $regular = max(0.0, $worked - $overtimeHours);
            $leaveHours = $this->paidLeaveHours($storeId, $row['staff_id'], $start, $end, $dayHours);

            $rateDec = Money::decimal($rate);
            $basePay = Money::minorFromMajor((string) $rateDec->multipliedBy(Money::decimal($regular + $leaveHours)), $currency);
            $overtimePay = Money::minorFromMajor((string) $rateDec->multipliedBy(Money::decimal($overtimeHours))->multipliedBy(Money::decimal($multiplier)), $currency);
            $tips = Money::minorFromMajor((float) $row['tips'], $currency);
            $net = $basePay + $overtimePay + $tips;

            PayrollItem::create([
                'payroll_run_id' => $run->id,
                'staff_id' => $row['staff_id'],
                'scheduled_hours' => $row['scheduled_hours'],
                'worked_hours' => $worked,
                'leave_hours' => $leaveHours,
                'overtime_hours' => round($overtimeHours, 2),
                'rate' => $rate,
                'base_pay' => Money::majorFromMinor($basePay, $currency),
                'overtime_pay' => Money::majorFromMinor($overtimePay, $currency),
                'tips' => Money::majorFromMinor($tips, $currency),
                'bonuses' => 0,
                'deductions' => 0,
                'adjustments' => [],
                'net' => Money::majorFromMinor($net, $currency),
            ]);
            $total += $net;
        }

        $run->update(['total_net' => Money::majorFromMinor($total, $currency)]);

        return $run->fresh('items');
    }

    /** Add a manual adjustment (bonus/deduction) to an item and recompute its net. */
    public function adjustItem(PayrollItem $item, string $label, float $amount): void
    {
        if ($item->run->isLocked()) {
            throw ValidationException::withMessages(['run' => ___('This run is locked.')]);
        }
        // P1-05: in minor units of the item's currency (adjustment amounts HALF_UP to its exponent; the stored
        // adjustment amounts stay major numbers in the JSON).
        $currency = $item->minorMoneyCurrency();
        $adjustments = (array) ($item->adjustments ?? []);
        $adjustments[] = ['label' => $label, 'amount' => Money::round($amount, Currencies::exponent($currency))];
        $minors = array_map(fn ($a) => Money::minorFromMajor((float) $a['amount'], $currency), $adjustments);
        $bonuses = array_sum(array_map(fn (int $m) => max(0, $m), $minors));
        $deductions = -array_sum(array_map(fn (int $m) => min(0, $m), $minors));
        $net = (int) $item->base_pay_minor + (int) $item->overtime_pay_minor + (int) $item->tips_minor + array_sum($minors);

        $item->update([
            'adjustments' => $adjustments,
            'bonuses' => Money::majorFromMinor($bonuses, $currency),
            'deductions' => Money::majorFromMinor($deductions, $currency),
            'net' => Money::majorFromMinor($net, $currency),
        ]);
        $this->refreshRunTotal($item->run);
    }

    /** P1-05: the run total = the exact sum of its items' net, each read in its own currency, at the run's exponent. */
    public function refreshRunTotal(PayrollRun $run): void
    {
        $sum = $run->items()->sum(DB::raw(PayrollItem::minorMoneySql('net')));
        $run->update(['total_net' => Money::round($sum ?? 0, Currencies::exponent($run->minorMoneyCurrency()))]);
    }

    public function submit(PayrollRun $run): void
    {
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ___('Only a draft can be submitted.')]);
        }
        $run->update(['status' => 'pending_approval']);
    }

    public function approve(PayrollRun $run, int $approverId): void
    {
        if ($run->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => ___('Only a submitted run can be approved.')]);
        }
        $run->update(['status' => 'approved', 'approved_by' => $approverId, 'approved_at' => now()]);

        // SYSTEMS-RUN P-P3: recognize wages in the GL (no-op unless accounting_gl on).
        try {
            PayrollGlPoster::postApproval($run->fresh('items'));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function rateFor(int $staffId, ?string $role, float $fallback): float
    {
        if ($role) {
            // P1-05: the exact stored rate in the store's currency (value() went through the column cast).
            $roleRate = StaffRoleRate::where('staff_id', $staffId)->where('role', $role)->first()?->money('hourly_rate');
            if ($roleRate !== null) {
                return $roleRate->toFloat();
            }
        }
        $base = StaffMember::find($staffId)?->money('base_wage');

        return $base !== null ? $base->toFloat() : $fallback;
    }

    private function paidLeaveHours(int $storeId, int $staffId, Carbon $start, Carbon $end, float $dayHours): float
    {
        $paidTypeIds = LeaveType::where('store_id', $storeId)->where('paid', true)->pluck('id');
        if ($paidTypeIds->isEmpty()) {
            return 0.0;
        }
        $days = 0.0;
        LeaveRequest::where('staff_id', $staffId)
            ->where('status', 'approved')
            ->whereIn('leave_type_id', $paidTypeIds)
            ->where('from_date', '<=', $end->toDateString())
            ->where('to_date', '>=', $start->toDateString())
            ->get()
            ->each(function ($req) use (&$days, $start, $end) {
                $f = Carbon::parse($req->from_date)->max($start);
                $t = Carbon::parse($req->to_date)->min($end);
                /* Diff CALENDAR DATES only. $end is endOfDay (23:59:59), so when the
                   leave extends past the period end, min() keeps that timestamp and
                   diffing it against $f (00:00:00) yields a fractional day under
                   Carbon 3's float diffInDays (e.g. 11.9999) — the +1 inclusive count
                   then overpays ~1 full day. Normalising both ends to their date
                   makes the diff a whole number again. */
                $span = (int) Carbon::parse($f->toDateString())->diffInDays(Carbon::parse($t->toDateString())) + 1;
                $days += $req->half_day ? $span * 0.5 : $span;
            });

        return round($days * $dayHours, 2);
    }
}
