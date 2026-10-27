<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Http\Controllers\User;

use App\Models\StaffMember;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Hr\Models\LeaveRequest;
use Juwri\Modules\Hr\Models\LeaveType;
use Juwri\Modules\Hr\Services\Hrm\LeaveService;

/**
 * SYSTEMS-RUN H-P2: owner leave management — approval queue, team calendar,
 * leave-type config, request-on-behalf. Tenant scoped; behind hrm_suite.
 */
class LeaveController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(private LeaveService $leave) {}

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        LeaveType::ensureSeeded($rid);

        $pending = LeaveRequest::where('store_id', $rid)->where('status', 'pending')
            ->with('staff', 'type')->orderBy('from_date')->get();
        $month = $request->query('month') ?: now()->format('Y-m');
        $monthStart = Carbon::parse($month.'-01');
        $calendar = LeaveRequest::where('store_id', $rid)->where('status', 'approved')
            ->whereDate('to_date', '>=', $monthStart->copy()->startOfMonth()->toDateString())
            ->whereDate('from_date', '<=', $monthStart->copy()->endOfMonth()->toDateString())
            ->with('staff', 'type')->get();

        return view('owner.team.leave', [
            'rid' => $rid,
            'pending' => $pending,
            'calendar' => $calendar,
            'month' => $month,
            'types' => LeaveType::where('store_id', $rid)->get(),
            'staff' => StaffMember::where('store_id', $rid)->get(['id', 'name']),
            'conflicts' => $pending->mapWithKeys(fn ($r) => [$r->id => $this->leave->shiftConflicts($r)]),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function requestLeave(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'staff_id' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date'],
            'half_day' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        abort_unless(StaffMember::where('store_id', $rid)->whereKey($data['staff_id'])->exists(), 422);
        $this->leave->request($rid, (int) $data['staff_id'], $data);

        return back()->with('success', ___('Leave requested.'));
    }

    public function storeType(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'paid' => ['nullable', 'boolean'],
            'annual_allowance_days' => ['nullable', 'integer', 'min:0'],
        ]);
        LeaveType::create([
            'store_id' => $rid,
            'name' => $data['name'],
            'paid' => (bool) ($data['paid'] ?? false),
            'annual_allowance_days' => (int) ($data['annual_allowance_days'] ?? 0),
        ]);

        return back()->with('success', ___('Leave type added.'));
    }

    public function approve(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $this->leave->approve($this->owned($id), auth()->id());

        return back()->with('success', ___('Leave approved.'));
    }

    public function reject(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $this->leave->reject($this->owned($id), auth()->id());

        return back()->with('success', ___('Leave rejected.'));
    }

    private function owned(int $id): LeaveRequest
    {
        return LeaveRequest::whereIn('store_id', $this->storeIds())->findOrFail($id);
    }
}
