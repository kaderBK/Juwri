<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Services\Rota;

use App\Services\FeatureAccess;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Juwri\Modules\Hr\Models\StaffShift;

/**
 * GAPS-RUN-2 B7-P1: staff rota operations. Tenant-scoped; overlap detection for the
 * same staff member; week publish (only published shifts reach staff).
 */
class RotaService
{
    /**
     * FIX-2 F2-7 (gap audit): the service-level truth for "does this store get
     * the rota" — kill-switch veto always, plus the store OWNER's entitlement
     * once `staff_rota` is plan_gated. Owner-scoped on purpose: the staff PWA
     * and any other non-dashboard transport authenticate someone who is not the
     * buyer.
     */
    public function enabledFor(?int $storeId): bool
    {
        return FeatureAccess::storeAllows($storeId, 'staff_rota');
    }

    /** Create a shift. Caller must pass a store_id the owner controls. */
    public function createShift(array $data): StaffShift
    {
        // FIX-2 F2-7: defense in depth — the write door re-asserts the gate.
        if (! $this->enabledFor((int) $data['store_id'])) {
            abort(403, ___('This feature is not included in your current plan.'));
        }

        return StaffShift::create([
            'store_id' => (int) $data['store_id'],
            'branch_id' => $data['branch_id'] ?? null,
            'staff_id' => (int) $data['staff_id'],
            'role' => $data['role'] ?? null,
            'starts_at' => Carbon::parse($data['starts_at']),
            'ends_at' => Carbon::parse($data['ends_at']),
            'note' => $data['note'] ?? null,
            'published' => (bool) ($data['published'] ?? false),
            'created_by' => $data['created_by'] ?? null,
        ]);
    }

    public function updateShift(StaffShift $shift, array $data): StaffShift
    {
        foreach (['branch_id', 'staff_id', 'role', 'note'] as $k) {
            if (array_key_exists($k, $data)) {
                $shift->{$k} = $data[$k];
            }
        }
        if (isset($data['starts_at'])) {
            $shift->starts_at = Carbon::parse($data['starts_at']);
        }
        if (isset($data['ends_at'])) {
            $shift->ends_at = Carbon::parse($data['ends_at']);
        }
        $shift->save();

        return $shift;
    }

    public function deleteShift(StaffShift $shift): void
    {
        $shift->delete();
    }

    /**
     * Does this staff member already have a shift overlapping [$start, $end)?
     * Excludes $exceptId (for edits). Half-open interval so back-to-back is fine.
     */
    public function overlaps(int $staffId, string $start, string $end, ?int $exceptId = null): bool
    {
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);

        return StaffShift::where('staff_id', $staffId)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where('starts_at', '<', $e)
            ->where('ends_at', '>', $s)
            ->exists();
    }

    /** Publish every shift in the given week (Mon–Sun) for a restaurant. */
    public function publishWeek(int $storeId, string $weekStart): int
    {
        [$from, $to] = $this->weekBounds($weekStart);

        return StaffShift::where('store_id', $storeId)
            ->whereBetween('starts_at', [$from, $to])
            ->update(['published' => true]);
    }

    /** All shifts (published or not) in a week — owner view. */
    public function weekShifts(int $storeId, string $weekStart): Collection
    {
        [$from, $to] = $this->weekBounds($weekStart);

        return StaffShift::where('store_id', $storeId)
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')
            ->get();
    }

    /** Only PUBLISHED shifts for a staff member from now forward — staff PWA view. */
    public function publishedShiftsForStaff(int $staffId, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $from = $from ?: now()->startOfDay();
        $to = $to ?: now()->copy()->addDays(14)->endOfDay();

        return StaffShift::where('staff_id', $staffId)
            ->where('published', true)
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')
            ->get();
    }

    /** @return array{0:Carbon,1:Carbon} Monday 00:00 .. Sunday 23:59 for the week containing $weekStart. */
    private function weekBounds(string $weekStart): array
    {
        $from = Carbon::parse($weekStart)->startOfWeek(Carbon::MONDAY);
        $to = $from->copy()->endOfWeek(Carbon::SUNDAY);

        return [$from, $to];
    }
}
