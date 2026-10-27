<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Http\Controllers\User;

use App\Models\Order;
use App\Services\Backup\DatasetStreamWriter;
use App\Support\StoreClock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Hr\Models\PayrollItem;
use Juwri\Modules\Hr\Models\PayrollRun;
use Juwri\Modules\Hr\Services\Payroll\PayrollEngine;
use Juwri\Modules\Hr\Services\Payroll\PayrollGlPoster;
use Juwri\Modules\Hr\Services\Payroll\TipPoolService;

/**
 * SYSTEMS-RUN P-P1: payroll runs — generate a period, adjust lines, submit,
 * approve. Tenant scoped; behind payroll_suite.
 */
class PayrollController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(private PayrollEngine $engine) {}

    private function ownedRun(int $id): PayrollRun
    {
        return PayrollRun::whereIn('store_id', $this->storeIds())->findOrFail($id);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $runs = PayrollRun::where('store_id', $rid)->latest('period_start')->limit(50)->get();

        return view('owner.team.payroll.index', [
            'rid' => $rid,
            'runs' => $runs,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function generate(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);
        $run = $this->engine->build($rid, $data['period_start'], $data['period_end']);

        return redirect()->route('stores.payroll.show', ['id' => $run->id, 'store_id' => $rid]);
    }

    public function show(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $run = $this->ownedRun($id);

        return view('owner.team.payroll.show', [
            'rid' => $run->store_id,
            'run' => $run->load('items.staff'),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function adjust(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $run = $this->ownedRun($id);
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric'],
        ]);
        $item = PayrollItem::where('payroll_run_id', $run->id)->findOrFail($data['item_id']);
        $this->engine->adjustItem($item, $data['label'], (float) $data['amount']);

        return back()->with('success', ___('Adjustment added.'));
    }

    public function submit(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $this->engine->submit($this->ownedRun($id));

        return back()->with('success', ___('Submitted for approval.'));
    }

    public function approve(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $this->engine->approve($this->ownedRun($id), auth()->id());

        return back()->with('success', ___('Payroll approved.'));
    }

    public function pool(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $run = $this->ownedRun($id);
        $data = $request->validate([
            'method' => ['required', 'in:by_hours,by_role_weight,custom_percent'],
            'role_weights' => ['nullable', 'array'],
            'percents' => ['nullable', 'array'],
        ]);
        (new TipPoolService)->apply($run, $data['method'], [
            'role_weights' => $data['role_weights'] ?? [],
            'percents' => $data['percents'] ?? [],
        ]);

        return back()->with('success', ___('Tips pooled and applied.'));
    }

    public function markPaid(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $run = $this->ownedRun($id);
        abort_unless($run->status === 'approved', 422, ___('Approve the run first.'));
        $run->update(['status' => 'paid', 'paid_at' => now()]);

        try {
            PayrollGlPoster::postPayment($run->fresh('items'));
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('success', ___('Marked as paid.'));
    }

    /** Bank-file export (CSV). Real bank execution is DEFERRED. */
    public function bankFile(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $run = $this->ownedRun($id)->load('items.staff');

        $rows = [['staff', 'national_id', 'net']];
        foreach ($run->items as $item) {
            $rows[] = [optional($item->staff)->name, optional($item->staff)->national_id, number_format((float) $item->net, 2, '.', '')];
        }
        // staff name/national_id are user-editable — route through the formula
        // neutralizer before quoting (a quoted "=..." cell still evaluates in Excel).
        $csv = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', DatasetStreamWriter::csvCell($v)).'"', $r)), $rows));

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="payroll-'.$run->id.'.csv"',
        ]);
    }

    public function labor(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $from = $request->query('from') ?: StoreClock::now($rid)->startOfMonth()->toDateString();
        $to = $request->query('to') ?: StoreClock::today($rid);

        // Labor cost = net of runs whose period overlaps the window, PRORATED to the
        // overlapping days. Summing each run's full total_net compared a whole pay
        // period's labor against only the window's sales — a month-long run on a
        // month-to-date view read as ~160%+ and tripped the over-threshold warning
        // falsely. Proration assumes labor is spread evenly across a run's days (an
        // approximation, but far closer than the full net).
        $winStart = Carbon::parse($from)->startOfDay();
        $winEnd = Carbon::parse($to)->startOfDay();
        $labor = 0.0;
        foreach (PayrollRun::where('store_id', $rid)
            ->where('period_start', '<=', $to)->where('period_end', '>=', $from)->get() as $run) {
            $ps = Carbon::parse($run->period_start)->startOfDay();
            $pe = Carbon::parse($run->period_end)->startOfDay();
            $runDays = $ps->diffInDays($pe) + 1;
            $ovStart = $ps->greaterThan($winStart) ? $ps : $winStart;
            $ovEnd = $pe->lessThan($winEnd) ? $pe : $winEnd;
            $ovDays = $ovStart->diffInDays($ovEnd) + 1;
            $fraction = $runDays > 0 ? min(1.0, max(0.0, $ovDays / $runDays)) : 1.0;
            $labor += (float) $run->total_net * $fraction;
        }

        // Sales = paid orders' totals in window.
        $sales = 0.0;
        foreach (Order::where('store_id', $rid)->where('is_paid', 1)
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->get() as $o) {
            $sales += (float) $o->total;
        }
        $laborPct = $sales > 0 ? round($labor / $sales * 100, 1) : null;

        return view('owner.team.payroll.labor', [
            'rid' => $rid,
            'from' => $from,
            'to' => $to,
            'labor' => round($labor, 2),
            'sales' => round($sales, 2),
            'laborPct' => $laborPct,
            'warn' => $laborPct !== null && $laborPct > (float) (store_options($rid, 'labor_pct_threshold', 30) ?: 30),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function payslip(Request $request, int $id, int $itemId)
    {
        $this->currentRestaurantId($request);
        $run = $this->ownedRun($id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->with('staff')->findOrFail($itemId);

        return view('owner.team.payroll.payslip', [
            'run' => $run,
            'item' => $item,
        ]);
    }
}
