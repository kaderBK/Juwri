<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Http\Controllers\User;

use App\Services\Backup\DatasetStreamWriter;
use App\Services\FeatureRegistry;
use App\Support\StoreClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Core\Services\Tenancy\TenantStorage;
use Juwri\Modules\Accounting\Models\Expense;
use Juwri\Modules\Accounting\Models\GlAccount;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Services\Accounting\ExpenseService;
use Juwri\Modules\Accounting\Services\Accounting\Gl\ChartOfAccounts;
use Juwri\Modules\Accounting\Services\Accounting\Gl\PeriodLock;
use Juwri\Modules\Accounting\Services\Accounting\GlExportService;
use Juwri\Modules\Accounting\Services\Accounting\GlReportService;
use Juwri\Modules\Media\Contracts\StorageQuotas;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SYSTEMS-RUN A-P2+: the built-in GL accounting dashboard (expenses now;
 * journals/reports/VAT/dashboard land in A-P3/A-P4). Tenant scoped; behind the
 * accounting_gl kill-switch. Coexists with the QB/Xero sync AccountingController.
 */
class GlAccountingController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(private ExpenseService $expenses) {}

    public function dashboard(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        ChartOfAccounts::ensure($rid);

        $svc = new GlReportService;
        $localNow = StoreClock::now($rid);
        $from = $localNow->copy()->startOfMonth()->toDateString();
        $to = $localNow->toDateString();
        $pl = $svc->profitAndLoss($rid, $from, $to);
        $bs = $svc->balanceSheet($rid, $to);
        $vat = $svc->vatReport($rid, $from, $to);

        // Cash position = sum of Cash & Bank (1100 subtree) balances.
        $cash = 0.0;
        foreach (GlAccount::where('store_id', $rid)->where('type', 'asset')->get() as $a) {
            if (in_array($a->code, ['1110', '1120', '1130'], true)) {
                $cash += $a->balance();
            }
        }

        // 6-month revenue vs expense trend.
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $mStart = $localNow->copy()->subMonths($i)->startOfMonth()->toDateString();
            $mEnd = $localNow->copy()->subMonths($i)->endOfMonth()->toDateString();
            $mpl = $svc->profitAndLoss($rid, $mStart, $mEnd);
            $trend[] = [
                'label' => $localNow->copy()->subMonths($i)->translatedFormat('M'),
                'revenue' => $mpl['total_revenue'],
                'expense' => $mpl['total_expense'],
            ];
        }

        return view('owner.accounting.dashboard', [
            'rid' => $rid,
            'cash' => money_round($cash, money_decimals(store_currency($rid))),
            'pl' => $pl,
            'bs' => $bs,
            'vat' => $vat,
            'trend' => $trend,
            'syncEnabled' => FeatureRegistry::isEnabled('accounting_sync'),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function expensesIndex(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        ChartOfAccounts::ensure($rid);

        $expenses = Expense::where('store_id', $rid)
            ->latest('date')->limit(200)->get();
        $accounts = GlAccount::where('store_id', $rid)
            ->where('type', 'expense')->where('archived', false)
            ->orderBy('code')->get();

        return view('owner.accounting.expenses', [
            'rid' => $rid,
            'expenses' => $expenses,
            'accounts' => $accounts,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function storeExpense(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'gl_account_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'payment_source' => ['nullable', 'in:cash,payable'],
            'recur_interval' => ['nullable', 'in:weekly,monthly'],
            'branch_id' => ['nullable', 'integer'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        // Guard the account belongs to this tenant.
        if (! empty($data['gl_account_id'])) {
            abort_unless(GlAccount::where('store_id', $rid)->where('id', $data['gl_account_id'])->exists(), 422);
        }

        if ($request->hasFile('receipt')) {
            $receipt = $request->file('receipt');
            $ownerId = (int) Store::where('id', $rid)->value('user_id');
            if ($ownerId && ! app(StorageQuotas::class)->allow($ownerId, $receipt->getSize())) {
                return back()->withErrors(['receipt' => ___('Storage limit reached. Delete some files or upgrade your plan.')])->withInput();
            }

            // PRIVATE disk: an expense receipt is a financial document (invoices, bank
            // slips, tax docs). On the public disk it was served with no auth/ownership
            // at /storage/expenses/{rid}/{file}. Store on the private local disk and serve
            // through the authed downloadReceipt route, matching StaffHrm documents.
            // P1-04 slice 7: new uploads under the account prefix (existing rows keep their stored path).
            $data['receipt_path'] = $receipt->store(TenantStorage::dir('expenses/'.$rid, TenantFill::accountForStore(DB::connection(), (int) $rid)), 'local');

            if ($ownerId) {
                app(StorageQuotas::class)->add($ownerId, $receipt->getSize());
            }
        }
        $data['created_by'] = auth()->id();

        $this->expenses->create($rid, $data);

        return back()->with('success', ___('Expense recorded.'));
    }

    /** Serve an expense receipt privately, scoped to the owner's restaurants. */
    public function downloadReceipt(Request $request, int $id): BinaryFileResponse
    {
        $this->currentRestaurantId($request);
        $expense = Expense::whereIn('store_id', $this->storeIds())->findOrFail($id);
        abort_unless(! empty($expense->receipt_path), 404);

        $abs = storage_path('app/'.$expense->receipt_path);
        // Legacy rows uploaded before the private-disk fix live under app/public.
        if (! is_file($abs)) {
            $abs = storage_path('app/public/'.$expense->receipt_path);
        }
        abort_unless(is_file($abs), 404);

        return response()->file($abs, ['Cache-Control' => 'private, no-store']);
    }

    public function destroyExpense(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $expense = Expense::whereIn('store_id', $this->storeIds())->findOrFail($id);
        $path = $expense->receipt_path;
        $expense->delete();

        // Unlink the receipt file too — destroy used to delete only the row, orphaning
        // the file on disk. Cover both the new private and legacy public locations.
        if ($path) {
            @unlink(storage_path('app/'.$path));
            @unlink(storage_path('app/public/'.$path));
        }

        return back()->with('success', ___('Expense deleted.'));
    }

    public function journals(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        ChartOfAccounts::ensure($rid);
        // P6-274: a date range (the screen shows the latest 100 in it; the CSV export has every line)
        $from = $this->dateParam($request, 'from');
        $to = $this->dateParam($request, 'to');
        $journals = GlJournal::where('store_id', $rid)
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->with('lines.account')->latest('date')->latest('id')->limit(100)->get();

        return view('owner.accounting.journals', [
            'rid' => $rid,
            'from' => $from,
            'to' => $to,
            'journals' => $journals,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function reports(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        ChartOfAccounts::ensure($rid);

        $from = $request->query('from') ?: StoreClock::now($rid)->startOfMonth()->toDateString();
        $to = $request->query('to') ?: StoreClock::today($rid);
        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;

        $svc = new GlReportService;

        return view('owner.accounting.reports', [
            'rid' => $rid,
            'from' => $from,
            'to' => $to,
            'branchId' => $branchId,
            'trialBalance' => $svc->trialBalance($rid, $from, $to, $branchId),
            'pl' => $svc->profitAndLoss($rid, $from, $to, $branchId),
            'balanceSheet' => $svc->balanceSheet($rid, $to, $branchId),
            'vat' => $svc->vatReport($rid, $from, $to, $branchId),
            'periodLocked' => PeriodLock::latest($rid),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function lockPeriod(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);
        PeriodLock::lock($rid, $data['period'], auth()->id());
        log_activity('books.period.close', null, ['period' => $data['period']], $rid); // P6-274

        return back()->with('success', ___('Period :p closed.', ['p' => $data['period']]));
    }

    /**
     * P6-274: the accountant export — journals (every line), trial balance or VAT for a date range, as CSV. Every cell
     * goes through the formula neutralizer (memos and account names are owner text).
     */
    public function export(Request $request): StreamedResponse
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(GlExportService::TYPES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;
        $branchId = ! empty($data['branch_id']) ? (int) $data['branch_id'] : null;
        $rows = (new GlExportService)->rows($data['type'], $rid, $from, $to, $branchId);
        log_activity('books.export', null, ['type' => $data['type'], 'from' => $from, 'to' => $to, 'branch_id' => $branchId], $rid);
        $name = 'books-'.$rid.'-'.str_replace('_', '-', $data['type']).'-'.($from ?: 'start').'-'.($to ?: 'today').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM: Excel opens Arabic account names correctly
            foreach ($rows as $row) {
                // a plain number (an amount, a negative balance) stays a number; any other cell is formula-neutralized
                fputcsv($out, array_map(fn ($v) => is_int($v) || (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v) === 1)
                    ? (string) $v : DatasetStreamWriter::csvCell($v), $row), escape: '\\');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function unlockPeriod(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        // P6-274: reopening closed books needs a reason, and leaves an audit trail (who, why, which months)
        $data = $request->validate(['period' => ['required', 'date_format:Y-m'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $reopened = PeriodLock::unlock($rid, $data['period']);
        log_activity('books.period.reopen', null, ['period' => $data['period'], 'reason' => trim($data['reason']),
            'reopened' => array_column($reopened, 'period'), 'was_locked_by' => array_column($reopened, 'locked_by')], $rid);

        return back()->with('success', ___('Period :p reopened.', ['p' => $data['period']]));
    }

    /** A Y-m-d query date, or null when absent or malformed. */
    private function dateParam(Request $request, string $key): ?string
    {
        $v = (string) $request->query($key, '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }
}
