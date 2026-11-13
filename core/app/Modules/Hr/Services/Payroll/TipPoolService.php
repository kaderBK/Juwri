<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Services\Payroll;

use App\Models\Order;
use Juwri\Modules\Hr\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;

/**
 * SYSTEMS-RUN P-P2: tip pooling. Pools order tips over a run's period and splits
 * them across the run's staff by one of three methods — by hours worked, by role
 * weight, or by custom percent. Rounding is sum-exact (remainder to the last
 * recipient). Preview does not persist; apply writes payroll_items.tips.
 */
class TipPoolService
{
    public const METHODS = ['by_hours', 'by_role_weight', 'custom_percent'];

    /** Total tips collected in the run's period (order tip_amount on paid orders). */
    public function poolTotal(PayrollRun $run): float
    {
        // P1-05: HALF_UP to the run currency's exponent (PHP-version independent).
        return Money::round((float) Order::where('store_id', $run->store_id)
            ->where('is_paid', 1)
            ->whereBetween('created_at', [
                $run->period_start->copy()->startOfDay(),
                $run->period_end->copy()->endOfDay(),
            ])
            ->sum(DB::raw(Order::minorMoneySql('tip_amount'))), Currencies::exponent($run->minorMoneyCurrency()));
    }

    /**
     * Compute each item's tip share. Returns [item_id => amount], sum == pool.
     *
     * @param  array  $config  ['method'=>..., 'role_weights'=>[role=>w], 'percents'=>[item_id=>pct]]
     */
    public function preview(PayrollRun $run, string $method, array $config = [], ?float $pool = null): array
    {
        if (!in_array($method, self::METHODS, true)) {
            throw ValidationException::withMessages(['method' => ___('Unknown tip pooling method.')]);
        }
        $pool = $pool ?? $this->poolTotal($run);
        $items = $run->items()->with('staff')->get();
        if ($items->isEmpty() || $pool <= 0) {
            return [];
        }

        $weights = [];
        foreach ($items as $item) {
            $weights[$item->id] = match ($method) {
                'by_hours'       => max(0.0, (float) $item->worked_hours),
                'by_role_weight' => (float) ($config['role_weights'][optional($item->staff)->role] ?? 1),
                'custom_percent' => max(0.0, (float) ($config['percents'][$item->id] ?? 0)),
            };
        }

        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            return [];
        }

        /* Largest-remainder (Hamilton) allocation in whole cents: floor every share to
           the cent, then hand the leftover cents out one-by-one to the largest
           fractional remainders. The shares sum EXACTLY to the pool and none can be
           negative. The old "remainder to the last recipient" scheme wrote a NEGATIVE
           tip to the final staff member when the rounded earlier shares overshot a tiny
           pool (e.g. pool 0.50 across 100 equal weights → first 99 round to 0.01 = 0.99,
           last = round(0.50 - 0.99) = -0.49). */
        // P1-05: in minor units of the run's currency (not hard-coded cents: fils/baisa on 3-decimal currencies,
        // whole yen on 0-decimal ones).
        $currency = $run->minorMoneyCurrency();
        $poolCents = Money::minorFromMajor($pool, $currency);
        $ids = array_keys($weights);

        $floorCents = [];
        $remainders = [];
        $assigned = 0;
        foreach ($ids as $id) {
            $exact = $poolCents * $weights[$id] / $totalWeight;
            $floor = (int) floor($exact);
            $floorCents[$id] = $floor;
            $remainders[$id] = $exact - $floor;
            $assigned += $floor;
        }

        $leftover = $poolCents - $assigned; // whole cents still to distribute (0..count-1)
        if ($leftover > 0) {
            // ids ordered by largest fractional remainder first
            $order = $ids;
            usort($order, fn ($a, $b) => $remainders[$b] <=> $remainders[$a]);
            for ($k = 0; $k < $leftover; $k++) {
                $floorCents[$order[$k % count($order)]] += 1;
            }
        }

        $alloc = [];
        foreach ($ids as $id) {
            $alloc[$id] = Money::majorFromMinor($floorCents[$id], $currency);
        }

        return $alloc;
    }

    /** Apply the split to the run's items (overwrites tips, recomputes net). */
    public function apply(PayrollRun $run, string $method, array $config = []): array
    {
        if ($run->isLocked()) {
            throw ValidationException::withMessages(['run' => ___('This run is locked.')]);
        }
        $alloc = $this->preview($run, $method, $config);
        foreach ($run->items as $item) {
            // P1-05: exact minor units of the item's currency (the stored amounts, not their display strings).
            $currency = $item->minorMoneyCurrency();
            $tips = Money::minorFromMajor((float) ($alloc[$item->id] ?? 0), $currency);
            $adjSum = array_sum(array_map(fn ($a) => Money::minorFromMajor((float) ($a['amount'] ?? 0), $currency), (array) ($item->adjustments ?? [])));
            $item->update([
                'tips' => Money::majorFromMinor($tips, $currency),
                'net' => Money::majorFromMinor((int) $item->base_pay_minor + (int) $item->overtime_pay_minor + $tips + $adjSum, $currency),
            ]);
        }
        (new PayrollEngine)->refreshRunTotal($run);

        return $alloc;
    }
}
