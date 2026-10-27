<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\Money;
use Juwri\Modules\Accounting\Models\GlAccount;
use Juwri\Modules\Accounting\Models\GlJournalLine;
use Juwri\Modules\Ordering\Contracts\OrderTax;

/**
 * SYSTEMS-RUN A-P3: financial reports over the GL. Pure read-side aggregation
 * (indexed SUMs, no N+1). All queries are restaurant-scoped and optionally
 * branch-scoped + date-bounded. P1-05: sums are integer minor units of the store
 * currency (no float drift, rounding per currency exponent); report arrays keep major floats. Debit-normal accounts (asset/expense) report
 * balance = Σdebit-Σcredit; credit-normal (liability/equity/revenue) the reverse.
 */
class GlReportService
{
    /**
     * Per-account debit/credit sums for a period. Returns rows:
     * [account, debit, credit, balance(signed by normal side)].
     */
    public function trialBalance(int $storeId, ?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $currency = store_currency($storeId);
        $sums = $this->accountSums($storeId, $from, $to, $branchId, $currency);
        $accounts = GlAccount::where('store_id', $storeId)->orderBy('code')->get();

        $rows = [];
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($accounts as $acct) {
            $d = $sums[$acct->id]['debit'] ?? 0;
            $c = $sums[$acct->id]['credit'] ?? 0;
            if ($d === 0 && $c === 0) {
                continue;
            }
            $totalDebit += $d;
            $totalCredit += $c;
            $rows[] = [
                'account' => $acct,
                'debit' => $this->major($d, $currency),
                'credit' => $this->major($c, $currency),
                'balance' => $this->major(in_array($acct->type, ['asset', 'expense'], true) ? $d - $c : $c - $d, $currency),
            ];
        }

        return [
            'rows' => $rows,
            'total_debit' => $this->major($totalDebit, $currency),
            'total_credit' => $this->major($totalCredit, $currency),
            'balanced' => $totalDebit === $totalCredit,
        ];
    }

    /** Profit & loss: revenue - expenses = net income. */
    public function profitAndLoss(int $storeId, ?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $currency = store_currency($storeId);
        $sums = $this->accountSums($storeId, $from, $to, $branchId, $currency);
        $accounts = GlAccount::where('store_id', $storeId)->orderBy('code')->get()->keyBy('id');

        $revenue = [];
        $expenses = [];
        $totalRevenue = 0;
        $totalExpense = 0;

        foreach ($sums as $accountId => $s) {
            $acct = $accounts[$accountId] ?? null;
            if (! $acct) {
                continue;
            }
            $d = $s['debit'];
            $c = $s['credit'];
            if ($acct->type === 'revenue') {
                $revenue[] = ['account' => $acct, 'amount' => $this->major($c - $d, $currency)];
                $totalRevenue += $c - $d;
            } elseif ($acct->type === 'expense') {
                $expenses[] = ['account' => $acct, 'amount' => $this->major($d - $c, $currency)];
                $totalExpense += $d - $c;
            }
        }

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'total_revenue' => $this->major($totalRevenue, $currency),
            'total_expense' => $this->major($totalExpense, $currency),
            'net_income' => $this->major($totalRevenue - $totalExpense, $currency),
        ];
    }

    /** Balance sheet: assets = liabilities + equity + retained (net income to date). */
    public function balanceSheet(int $storeId, ?string $asOf = null, ?int $branchId = null): array
    {
        $currency = store_currency($storeId);
        $sums = $this->accountSums($storeId, null, $asOf, $branchId, $currency);
        $accounts = GlAccount::where('store_id', $storeId)->orderBy('code')->get()->keyBy('id');

        $assets = [];
        $liabilities = [];
        $equity = [];
        $totalAssets = 0;
        $totalLiabilities = 0;
        $totalEquity = 0;
        $retained = 0; // net income accumulates into equity

        foreach ($sums as $accountId => $s) {
            $acct = $accounts[$accountId] ?? null;
            if (! $acct) {
                continue;
            }
            $d = $s['debit'];
            $c = $s['credit'];
            switch ($acct->type) {
                case 'asset':
                    $assets[] = ['account' => $acct, 'amount' => $this->major($d - $c, $currency)];
                    $totalAssets += $d - $c;
                    break;
                case 'liability':
                    $liabilities[] = ['account' => $acct, 'amount' => $this->major($c - $d, $currency)];
                    $totalLiabilities += $c - $d;
                    break;
                case 'equity':
                    $equity[] = ['account' => $acct, 'amount' => $this->major($c - $d, $currency)];
                    $totalEquity += $c - $d;
                    break;
                case 'revenue':
                    $retained += $c - $d;
                    break;
                case 'expense':
                    $retained -= $d - $c;
                    break;
            }
        }

        $totalEquityWithRetained = $totalEquity + $retained;

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'retained_earnings' => $this->major($retained, $currency),
            'total_assets' => $this->major($totalAssets, $currency),
            'total_liabilities' => $this->major($totalLiabilities, $currency),
            'total_equity' => $this->major($totalEquityWithRetained, $currency),
            'balanced' => $totalAssets === $totalLiabilities + $totalEquityWithRetained,
        ];
    }

    /** VAT report: output VAT (2200), input VAT (1400), net due. ZATCA-aligned fields. */
    public function vatReport(int $storeId, ?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $currency = store_currency($storeId);
        $sums = $this->accountSums($storeId, $from, $to, $branchId, $currency);
        $output = GlAccount::where('store_id', $storeId)->where('code', '2200')->value('id');
        $input = GlAccount::where('store_id', $storeId)->where('code', '1400')->value('id');

        // Output VAT is credit-normal; input VAT is debit-normal.
        $outputVat = $output ? ($sums[$output]['credit'] ?? 0) - ($sums[$output]['debit'] ?? 0) : 0;
        $inputVat = $input ? ($sums[$input]['debit'] ?? 0) - ($sums[$input]['credit'] ?? 0) : 0;

        // Revenue base for the period (net sales).
        $pl = $this->profitAndLoss($storeId, $from, $to, $branchId);

        return [
            'output_vat' => $this->major($outputVat, $currency),
            'input_vat' => $this->major($inputVat, $currency),
            'net_vat_due' => $this->major($outputVat - $inputVat, $currency),
            'sales_base' => $pl['total_revenue'],
            // P6-259: the STORE's rate (not the platform default) and, per rate, the period's sales base + VAT
            'rate' => app(OrderTax::class)->storeRate($storeId),
            'rates' => $this->vatByRate($storeId, $from, $to, $branchId, $currency),
            'mode' => app(OrderTax::class)->perLineEnabled($storeId) ? 'per_line' : 'order',
            'inclusive' => app(OrderTax::class)->pricesIncludeVat($storeId),
            'decimals' => money_decimals($currency),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * P6-259: the period's paid orders (the same sales journals the VAT accounts sum) grouped by VAT rate.
     *
     * @return list<array{rate: float|null, category: string, net: float, vat: float}>
     */
    private function vatByRate(int $storeId, ?string $from, ?string $to, ?int $branchId, string $currency): array
    {
        $ids = DB::table('accounting_journals')
            ->where('store_id', $storeId)->where('source_type', 'Order')->where('rule_key', 'order_paid')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->pluck('source_id');
        $minor = [];
        foreach ($ids->chunk(500) as $chunk) {
            $orders = Order::query()->where('store_id', $storeId)->whereIn('id', $chunk->all())->with('items')->get();
            foreach (app(OrderTax::class)->byRateMinor($orders, $storeId, $currency) as $b) {
                $k = ($b['rate'] === null ? 'x' : number_format($b['rate'], 2, '.', '')).$b['category'];
                $minor[$k] ??= ['rate' => $b['rate'], 'category' => $b['category'], 'net' => 0, 'vat' => 0];
                $minor[$k]['net'] += $b['net'];
                $minor[$k]['vat'] += $b['vat'];
            }
        }
        $out = array_values($minor);
        usort($out, fn ($a, $b) => [(float) $b['rate'], $a['category']] <=> [(float) $a['rate'], $b['category']]);

        return array_map(fn ($b) => ['rate' => $b['rate'], 'category' => $b['category'],
            'net' => $this->major($b['net'], $currency), 'vat' => $this->major($b['vat'], $currency)], $out);
    }

    /** Minor units of $currency as the major float the report arrays carry. */
    private function major(int $minor, string $currency): float
    {
        return Money::majorFromMinor($minor, $currency);
    }

    /**
     * Per-account debit/credit sums in MINOR units of the store currency $currency. Each leg is read as a major
     * amount in its own currency by SQL (a line written before a store currency change keeps its number), then the
     * per-account sum is rounded HALF_UP to $currency's exponent (P1-05: PHP-version independent).
     *
     * @return array<int, array{debit: int, credit: int}>
     */
    private function accountSums(int $storeId, ?string $from, ?string $to, ?int $branchId, string $currency): array
    {
        // Laravel prefixes the table alias too (`accounting_journal_lines as l` →
        // `<prefix>accounting_journal_lines as <prefix>l`), and raw SUM() expressions aren't
        // rewritten — so the alias inside DB::raw must carry the prefix explicitly.
        $a = DB::getTablePrefix().'l';
        $q = DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.store_id', $storeId)
            ->when($branchId, fn ($q) => $q->where('j.branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('j.date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('j.date', '<=', $to))
            ->groupBy('l.gl_account_id')
            // P1-05: each leg as a major amount from its *_minor column in its own currency.
            ->select('l.gl_account_id', DB::raw('SUM('.GlJournalLine::minorMoneySql('debit', $a.'.').') as debit'), DB::raw('SUM('.GlJournalLine::minorMoneySql('credit', $a.'.').') as credit'));

        $out = [];
        foreach ($q->get() as $row) {
            $out[$row->gl_account_id] = [
                'debit' => Money::minorFromMajor($row->debit ?? 0, $currency),
                'credit' => Money::minorFromMajor($row->credit ?? 0, $currency),
            ];
        }

        return $out;
    }
}
