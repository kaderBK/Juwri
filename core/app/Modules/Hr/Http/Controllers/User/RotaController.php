<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Http\Controllers\User;

use App\Models\StaffMember;
use App\Services\Backup\DatasetStreamWriter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Hr\Models\StaffShift;
use Juwri\Modules\Hr\Services\Rota\PayrollService;
use Juwri\Modules\Hr\Services\Rota\RotaService;

/**
 * GAPS-RUN-2 B7-P2: owner rota — week grid, add/edit/delete shift, publish.
 * Tenant scoped; behind the staff_rota kill-switch.
 */
class RotaController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(private RotaService $rota) {}

    private function ownedShift(int $id, array $ids): StaffShift
    {
        return StaffShift::whereIn('store_id', $ids)->findOrFail($id);
    }

    private function ownedStaff(int $staffId, int $rid): StaffMember
    {
        return StaffMember::where('store_id', $rid)->findOrFail($staffId);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        // rescue(): a garbage ?week=xyz would make Carbon::parse throw and 500 the page.
        $weekStart = rescue(fn () => Carbon::parse($request->query('week', 'now')), Carbon::now(), false)->startOfWeek(Carbon::MONDAY);

        $staff = StaffMember::where('store_id', $rid)->orderBy('name')->get();
        $shifts = $this->rota->weekShifts($rid, $weekStart->toDateString())->groupBy('staff_id');

        return view('owner.team.rota', [
            'rid' => $rid,
            'weekStart' => $weekStart,
            'days' => collect(range(0, 6))->map(fn ($i) => $weekStart->copy()->addDays($i)),
            'staff' => $staff,
            'shifts' => $shifts,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function store(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'staff_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'role' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->ownedStaff((int) $data['staff_id'], $rid); // 404 if cross-tenant

        $overlap = $this->rota->overlaps((int) $data['staff_id'], $data['starts_at'], $data['ends_at']);

        $this->rota->createShift(array_merge($data, [
            'store_id' => $rid,
            'created_by' => auth()->id(),
        ]));

        return back()->with('success', $overlap
            ? ___('Shift saved — note: it overlaps another shift for this staff member.')
            : ___('Shift saved.'));
    }

    public function destroy(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $shift = $this->ownedShift($id, $this->storeIds());
        $this->rota->deleteShift($shift);

        return back()->with('success', ___('Shift removed.'));
    }

    public function publish(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $week = $request->input('week', 'now');
        $n = $this->rota->publishWeek($rid, Carbon::parse($week)->toDateString());

        return back()->with('success', ___(':n shift(s) published.', ['n' => $n]));
    }

    public function payroll(Request $request, PayrollService $payroll)
    {
        $rid = $this->currentRestaurantId($request);
        [$from, $to] = $this->period($request);
        $report = $payroll->report($rid, $from, $to);

        return view('owner.team.rota-payroll', [
            'rid' => $rid,
            'report' => $report,
            'from' => $from,
            'to' => $to,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function payrollCsv(Request $request, PayrollService $payroll)
    {
        $rid = $this->currentRestaurantId($request);
        [$from, $to] = $this->period($request);
        $report = $payroll->report($rid, $from, $to);

        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=payroll_'.$from.'_'.$to.'.csv',
        ];

        return response()->streamDownload(function () use ($report) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, ['Staff', 'Role', 'Scheduled hours', 'Worked hours', 'Open clock pairs', 'Rate', 'Pay', 'Tips'], escape: '\\');
            foreach ($report['rows'] as $r) {
                // staff name/role are user-editable — neutralize spreadsheet formula injection.
                fputcsv($file, array_map([DatasetStreamWriter::class, 'csvCell'], [$r['name'], $r['role'], $r['scheduled_hours'], $r['worked_hours'], $r['open_clock_pairs'], $r['rate'], $r['pay'], $r['tips']]), escape: '\\');
            }
            fclose($file);
        }, 'payroll_'.$from.'_'.$to.'.csv', $headers);
    }

    /** @return array{0:string,1:string} [from, to] date strings (default: current month). */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', function ($attribute, $value, $fail) use ($request) {
                $from = $request->input('from');
                if (! is_string($from) || ! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    return;
                }
                $start = Carbon::createFromFormat('Y-m-d', $from);
                $end = Carbon::createFromFormat('Y-m-d', $value);
                if (! $start || ! $end) {
                    return;
                }
                if ($end->lt($start)) {
                    $fail(___('The :attribute must be on or after the start date.'));
                } elseif ($start->diffInDays($end) + 1 > 92) {
                    $fail(___('The :attribute must be within 92 days (inclusive) of the start date.'));
                }
            }],
        ]);

        return [
            $data['from'] ?? now()->startOfMonth()->toDateString(),
            $data['to'] ?? now()->toDateString(),
        ];
    }
}
