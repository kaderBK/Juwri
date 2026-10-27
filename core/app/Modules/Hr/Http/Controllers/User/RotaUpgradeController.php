<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Http\Controllers\User;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Hr\Models\ShiftSwap;
use Juwri\Modules\Hr\Models\StaffShift;
use Juwri\Modules\Hr\Services\Team\RotaUpgradeService;

/**
 * SYSTEMS-RUN T-P3: owner rota-upgrade console — open-shift pool + claim
 * approvals + swap approvals. Tenant scoped; behind team_comms.
 */
class RotaUpgradeController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(private RotaUpgradeService $rota) {}

    private function ownedShift(int $id): StaffShift
    {
        return StaffShift::whereIn('store_id', $this->storeIds())->findOrFail($id);
    }

    private function ownedSwap(int $id): ShiftSwap
    {
        return ShiftSwap::whereIn('store_id', $this->storeIds())->findOrFail($id);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        return view('owner.team.rota-upgrades', [
            'rid' => $rid,
            'openShifts' => StaffShift::where('store_id', $rid)->where('is_open', true)->orderBy('starts_at')->get(),
            'pendingClaims' => StaffShift::where('store_id', $rid)->where('claim_status', 'pending')->get(),
            'swaps' => ShiftSwap::where('store_id', $rid)->whereIn('status', ['requested', 'accepted'])->get(),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function openShift(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $this->rota->openShift($this->ownedShift($id));

        return back()->with('success', ___('Shift opened to the pool.'));
    }

    public function approveClaim(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $this->rota->approveClaim($this->ownedShift($id));

        return back()->with('success', ___('Claim approved.'));
    }

    public function approveSwap(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $this->rota->approveSwap($this->ownedSwap($id));

        return back()->with('success', ___('Swap approved.'));
    }
}
