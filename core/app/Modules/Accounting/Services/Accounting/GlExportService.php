<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting;

use Juwri\Core\Money\Money;
use Juwri\Modules\Accounting\Models\GlJournal;

/**
 * P6-274: the accountant export — the books for a date range as CSV rows (header first): every journal line, the
 * trial balance, or the VAT report. Amounts are plain numbers at the store currency's decimals (no grouping, a dot),
 * the same minor-unit sums the reports show, so the export reconciles to the books to the last fil/halala.
 */
final class GlExportService
{
    public const TYPES = ['journals', 'trial_balance', 'vat'];

    /** @return list<list<string|int|null>> */
    public function rows(string $type, int $storeId, ?string $from, ?string $to, ?int $branchId = null): array
    {
        return match ($type) {
            'journals' => $this->journals($storeId, $from, $to, $branchId),
            'trial_balance' => $this->trialBalance($storeId, $from, $to, $branchId),
            'vat' => $this->vat($storeId, $from, $to, $branchId),
            default => throw new \InvalidArgumentException('Unknown export type'),
        };
    }

    /** @return list<list<string|int|null>> every journal line in range, then a totals row (debit = credit) */
    private function journals(int $storeId, ?string $from, ?string $to, ?int $branchId): array
    {
        $currency = store_currency($storeId);
        $out = [['date', 'journal_no', 'journal_id', 'source_type', 'source_id', 'rule', 'journal_memo', 'branch_id',
            'account_code', 'account_name', 'debit', 'credit', 'line_memo', 'currency']];
        $debit = 0;
        $credit = 0;
        GlJournal::query()->where('store_id', $storeId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->with('lines.account')->orderBy('date')->orderBy('id')
            ->chunk(500, function ($journals) use (&$out, &$debit, &$credit, $currency) {
                foreach ($journals as $j) {
                    foreach ($j->lines->sortBy('id') as $l) {
                        $d = Money::minorFromMajor((float) $l->debit, $currency);
                        $c = Money::minorFromMajor((float) $l->credit, $currency);
                        $debit += $d;
                        $credit += $c;
                        $out[] = [$j->date->toDateString(), $j->journal_no, (int) $j->id, $j->source_type, $j->source_id, $j->rule_key,
                            $j->memo, $j->branch_id, $l->account?->code, $l->account?->displayName(), $this->amount($d, $currency),
                            $this->amount($c, $currency), $l->memo, $currency];
                    }
                }
            });
        $out[] = ['TOTAL', null, null, null, null, null, null, null, null, null, $this->amount($debit, $currency), $this->amount($credit, $currency), null, $currency];

        return $out;
    }

    /** @return list<list<string|int|null>> */
    private function trialBalance(int $storeId, ?string $from, ?string $to, ?int $branchId): array
    {
        $currency = store_currency($storeId);
        $tb = (new GlReportService)->trialBalance($storeId, $from, $to, $branchId);
        $out = [['account_code', 'account_name', 'type', 'debit', 'credit', 'balance', 'currency']];
        foreach ($tb['rows'] as $r) {
            $out[] = [$r['account']->code, $r['account']->displayName(), $r['account']->type, $this->major($r['debit'], $currency),
                $this->major($r['credit'], $currency), $this->major($r['balance'], $currency), $currency];
        }
        $out[] = ['TOTAL', null, null, $this->major($tb['total_debit'], $currency), $this->major($tb['total_credit'], $currency), null, $currency];

        return $out;
    }

    /** @return list<list<string|int|null>> */
    private function vat(int $storeId, ?string $from, ?string $to, ?int $branchId): array
    {
        $currency = store_currency($storeId);
        $v = (new GlReportService)->vatReport($storeId, $from, $to, $branchId);
        $out = [['line', 'rate', 'category', 'net', 'vat', 'currency']];
        foreach ($v['rates'] ?? [] as $r) {
            $out[] = ['sales', $r['rate'] === null ? null : number_format((float) $r['rate'], 2, '.', ''), $r['category'],
                $this->major($r['net'], $currency), $this->major($r['vat'], $currency), $currency];
        }
        $out[] = ['output_vat', null, null, null, $this->major($v['output_vat'], $currency), $currency];
        $out[] = ['input_vat', null, null, null, $this->major($v['input_vat'], $currency), $currency];
        $out[] = ['net_vat_due', null, null, null, $this->major($v['net_vat_due'], $currency), $currency];

        return $out;
    }

    private function amount(int $minor, string $currency): string
    {
        return number_format(Money::majorFromMinor($minor, $currency), money_decimals($currency), '.', '');
    }

    private function major(float $major, string $currency): string
    {
        return $this->amount(Money::minorFromMajor($major, $currency), $currency);
    }
}
