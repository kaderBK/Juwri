<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Services\Hrm;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Juwri\Modules\Hr\Models\LeaveRequest;
use Juwri\Modules\Hr\Models\LeaveType;
use Juwri\Modules\Hr\Models\StaffShift;

/**
 * SYSTEMS-RUN H-P2: leave requests, balances, approval transitions, rota
 * conflict detection.
 */
class LeaveService
{
    /** Create a pending request. */
    public function request(int $storeId, int $staffId, array $data): LeaveRequest
    {
        $type = LeaveType::where('store_id', $storeId)->findOrFail($data['leave_type_id']);
        $from = Carbon::parse($data['from_date']);
        $to = Carbon::parse($data['to_date']);
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to_date' => ___('End date is before start date.')]);
        }

        return LeaveRequest::create([
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'leave_type_id' => $type->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'half_day' => (bool) ($data['half_day'] ?? false),
            'status' => ($data['status'] ?? 'pending') === 'draft' ? 'draft' : 'pending',
            'note' => $data['note'] ?? null,
        ]);
    }

    public function approve(LeaveRequest $req, int $approverId): void
    {
        $this->assertPending($req);
        $req->update(['status' => 'approved', 'approver_id' => $approverId, 'decided_at' => now()]);
    }

    public function reject(LeaveRequest $req, int $approverId): void
    {
        $this->assertPending($req);
        $req->update(['status' => 'rejected', 'approver_id' => $approverId, 'decided_at' => now()]);
    }

    private function assertPending(LeaveRequest $req): void
    {
        if (! in_array($req->status, ['pending', 'draft'], true)) {
            throw ValidationException::withMessages(['status' => ___('Only a pending request can be decided.')]);
        }
    }

    /** Remaining balance = annual allowance − approved days this year. */
    public function balance(int $staffId, LeaveType $type, ?int $year = null): float
    {
        $year = $year ?: (int) now()->year;
        $used = LeaveRequest::where('staff_id', $staffId)
            ->where('leave_type_id', $type->id)
            ->where('status', 'approved')
            ->whereYear('from_date', $year)
            ->get()
            ->sum(fn ($r) => $r->days());

        return round($type->annual_allowance_days - $used, 2);
    }

    /** Approved shifts that overlap a request's date range (conflict warning). */
    public function shiftConflicts(LeaveRequest $req): int
    {
        try {
            return StaffShift::where('staff_id', $req->staff_id)
                ->whereDate('starts_at', '>=', $req->from_date->toDateString())
                ->whereDate('starts_at', '<=', $req->to_date->toDateString())
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
