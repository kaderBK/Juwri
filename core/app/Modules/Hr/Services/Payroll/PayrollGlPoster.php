<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Services\Payroll;

use Juwri\Modules\Hr\Models\PayrollRun;
use App\Services\FeatureRegistry;
use Juwri\Core\Contracts\Accounting\GeneralLedger;
use Juwri\Core\Money\Money;

/**
 * SYSTEMS-RUN P-P3: post payroll into the GL. On approve — recognize the wage
 * expense and clear the tip liability against wages-payable. On paid — settle
 * wages-payable against cash. No-op unless accounting_gl is on; idempotent per
 * (PayrollRun, rule_key). Reporting only — never a money path.
 */
class PayrollGlPoster
{
    public static function postApproval(PayrollRun $run): void
    {
        if (!FeatureRegistry::isEnabled('accounting_gl')) {
            return;
        }
        $rid = (int) $run->store_id;
        $items = $run->items;
        // P1-05: exact sums of the stored minor units, in the run's currency.
        $currency = $run->minorMoneyCurrency();
        $tipsMinor = (int) $items->sum('tips_minor');
        $netMinor = (int) $items->sum('net_minor');
        if ($netMinor <= 0) {
            return;
        }
        $tips = Money::majorFromMinor($tipsMinor, $currency);
        $net = Money::majorFromMinor($netMinor, $currency);
        // Any residual (bonuses net of deductions) rolls into wage expense.
        $wageExpense = Money::majorFromMinor($netMinor - $tipsMinor, $currency);

        $lines = [
            ['code' => '6100', 'debit' => $wageExpense, 'credit' => 0, 'memo' => 'Wages'],
        ];
        if ($tips > 0) {
            $lines[] = ['code' => '2310', 'debit' => $tips, 'credit' => 0, 'memo' => 'Clear tips payable'];
        }
        $lines[] = ['code' => '2300', 'debit' => 0, 'credit' => $net, 'memo' => 'Wages payable'];

        // P1-13: the ledger is the Accounting module, reached through Core's GeneralLedger contract (Hr may not import it).
        app(GeneralLedger::class)->postJournal($rid, $lines, 'PayrollRun', (int) $run->id, 'payroll_approved', 'Payroll ' . $run->period_start->toDateString(), $run->period_end);
    }

    public static function postPayment(PayrollRun $run): void
    {
        if (!FeatureRegistry::isEnabled('accounting_gl')) {
            return;
        }
        $rid = (int) $run->store_id;
        $netMinor = (int) $run->items->sum('net_minor');
        if ($netMinor <= 0) {
            return;
        }
        $net = Money::majorFromMinor($netMinor, $run->minorMoneyCurrency());
        app(GeneralLedger::class)->postJournal($rid, [
            ['code' => '2300', 'debit' => $net, 'credit' => 0, 'memo' => 'Settle wages payable'],
            ['code' => '1110', 'debit' => 0, 'credit' => $net, 'memo' => 'Payroll paid'],
        ], 'PayrollRun', (int) $run->id, 'payroll_paid', 'Payroll paid ' . $run->period_start->toDateString(), now());
    }
}
