<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Services\Team;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Juwri\Modules\Hr\Models\LeaveRequest;
use Juwri\Modules\Hr\Models\ShiftSwap;
use Juwri\Modules\Hr\Models\StaffAvailability;
use Juwri\Modules\Hr\Models\StaffShift;

/**
 * SYSTEMS-RUN T-P3: rota upgrades — availability, open-shift claim, swap flow,
 * and conflict detection (availability + H-P2 approved leave).
 */
class RotaUpgradeService
{
    public function setAvailability(int $staffId, int $weekday, string $preference, ?string $from = null, ?string $to = null): StaffAvailability
    {
        $preference = in_array($preference, ['preferred', 'unavailable'], true) ? $preference : 'preferred';

        return StaffAvailability::updateOrCreate(
            ['staff_id' => $staffId, 'weekday' => $weekday, 'preference' => $preference],
            ['from_time' => $from, 'to_time' => $to]
        );
    }

    /** Warnings for assigning a shift: unavailable-day and approved-leave overlap. */
    public function conflicts(int $staffId, Carbon $startsAt): array
    {
        $warnings = [];
        $weekday = (int) $startsAt->dayOfWeek;
        if (StaffAvailability::where('staff_id', $staffId)->where('weekday', $weekday)->where('preference', 'unavailable')->exists()) {
            $warnings[] = 'unavailable';
        }
        $onLeave = LeaveRequest::where('staff_id', $staffId)->where('status', 'approved')
            ->whereDate('from_date', '<=', $startsAt->toDateString())
            ->whereDate('to_date', '>=', $startsAt->toDateString())
            ->exists();
        if ($onLeave) {
            $warnings[] = 'on_leave';
        }

        return $warnings;
    }

    public function openShift(StaffShift $shift): void
    {
        $shift->update(['is_open' => true, 'staff_id' => $shift->staff_id, 'claim_status' => null, 'claimed_by' => null]);
    }

    public function claim(StaffShift $shift, int $staffId): void
    {
        if (! $shift->is_open) {
            throw ValidationException::withMessages(['shift' => ___('This shift is not open.')]);
        }
        $shift->update(['claimed_by' => $staffId, 'claim_status' => 'pending']);
    }

    public function approveClaim(StaffShift $shift): void
    {
        if ($shift->claim_status !== 'pending' || ! $shift->claimed_by) {
            throw ValidationException::withMessages(['shift' => ___('No pending claim.')]);
        }
        $shift->update(['staff_id' => $shift->claimed_by, 'is_open' => false, 'claim_status' => 'approved']);
    }

    public function requestSwap(int $storeId, StaffShift $shift, int $requesterId, ?int $targetId = null): ShiftSwap
    {
        return ShiftSwap::create([
            'store_id' => $storeId,
            'shift_id' => $shift->id,
            'requester_staff_id' => $requesterId,
            'target_staff_id' => $targetId,
            'status' => 'requested',
        ]);
    }

    public function acceptSwap(ShiftSwap $swap, int $colleagueId): void
    {
        if ($swap->status !== 'requested') {
            throw ValidationException::withMessages(['swap' => ___('Swap is not open.')]);
        }
        $swap->update(['target_staff_id' => $colleagueId, 'status' => 'accepted']);
    }

    public function approveSwap(ShiftSwap $swap): void
    {
        if ($swap->status !== 'accepted' || ! $swap->target_staff_id) {
            throw ValidationException::withMessages(['swap' => ___('Swap not accepted yet.')]);
        }
        $swap->shift->update(['staff_id' => $swap->target_staff_id]);
        $swap->update(['status' => 'approved']);
    }
}
