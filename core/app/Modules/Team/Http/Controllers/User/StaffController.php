<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Http\Controllers\User;

use App\Models\KitchenStation;
use App\Models\StaffMember;
use App\Services\Billing\PlanLimitSync;
use App\Services\Entitlements;
use App\Services\StaffTwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Rules\GloballyUnique;
use Juwri\Modules\Messaging\Contracts\MobilePush;
use Juwri\Modules\Team\Models\StaffToken;

class StaffController extends Controller
{
    public function index()
    {
        // P6-220: bring the staff in line with the plan before showing them (downgrade → newest switched off).
        app(PlanLimitSync::class)->sync(auth()->user());
        $planSuspended = PlanLimitSync::suspendedCount('staff', auth()->user());
        $staffLimit = PlanLimitSync::limit('staff', auth()->user());

        $staff = StaffMember::forOwner(auth()->id())
            ->with('store', 'branch', 'passkeys')
            ->orderBy('store_id')
            ->orderBy('name')
            ->get();

        $stores = Store::where('user_id', auth()->id())->get(['id', 'title']);
        $branches = Branch::whereIn('store_id', $stores->pluck('id'))
            ->orderBy('store_id')->orderBy('position')->get();
        $stations = KitchenStation::whereIn('store_id', $stores->pluck('id'))
            ->where('active', true)->orderBy('store_id')->orderBy('position')->get();

        // BL-036 A2b: the permission sheet per store (only switches for features the store has) and, per member, how
        // many of those keys are on. Built here, not in the view (no closures in views).
        $permGroupsByStore = [];
        foreach ($staff->pluck('store_id')->unique() as $storeId) {
            $permGroupsByStore[(int) $storeId] = $this->permissionGroups((int) $storeId);
        }
        $permCounts = [];
        foreach ($staff as $m) {
            $keys = array_keys(array_merge([], ...array_values($permGroupsByStore[(int) $m->store_id] ?? [])));
            $permCounts[$m->id] = count(array_filter($keys, fn ($k) => $m->hasPermission($k)));
        }

        return view('owner.team.staff', compact('staff', 'stores', 'branches', 'stations', 'planSuspended', 'staffLimit', 'permGroupsByStore', 'permCounts'));
    }

    /**
     * The grantable keys of one store, grouped as in StaffMember::PERMISSION_CATALOG: hotel keys only with the hotel on,
     * `feature` keys only on a plan that has the feature; empty groups dropped. update() keeps overrides for keys not shown.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function permissionGroups(int $storeId): array
    {
        $hotelLive = app(RoomCharge::class)->enabled($storeId);
        $owner = auth()->user();
        $groups = [];
        foreach (StaffMember::PERMISSION_CATALOG as $label => $perms) {
            $shown = array_filter($perms, fn ($spec) => (empty($spec['hotel']) || $hotelLive)
                && (empty($spec['feature']) || plan_feature($spec['feature'], $owner)));
            if ($shown !== []) {
                $groups[$label] = $shown;
            }
        }

        return $groups;
    }

    public function store(Request $request)
    {
        $this->denyStaffSession();
        $storeIds = owner_store_ids(); // S13

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'email' => ['required', 'email', 'max:120', new GloballyUnique('staff_members', 'email')],
            'password' => ['required', 'string', Password::defaults()], // S8
            'pin' => 'nullable|digits_between:4,6',
            'role' => 'required|in:manager,cashier,kitchen,waiter',
            'store_id' => 'required|integer',
            'branch_id' => 'nullable|integer',
            'station_id' => 'nullable|integer',
        ]);

        if (! in_array((int) $data['store_id'], $storeIds)) {
            abort(403);
        }

        // E3: per-plan staff cap (per store). Absent `staff_limit` plan key →
        // unlimited (999 sentinel), i.e. exactly today's ungated behavior.
        $limit = effective_limit('staff', auth()->user());
        if (! Entitlements::isUnlimitedSentinel($limit)
            && StaffMember::where('store_id', (int) $data['store_id'])->count() >= $limit) {
            return back()->with('error', ___('Limit exceeded, please upgrade your membership.'));
        }

        // #76: branch must belong to the chosen restaurant (null = all branches).
        if (! empty($data['branch_id'])) {
            Branch::where('id', $data['branch_id'])->where('store_id', $data['store_id'])->firstOrFail();
        } else {
            $data['branch_id'] = null;
        }

        // #77: station must belong to the chosen restaurant (null = all stations).
        if (! empty($data['station_id'])) {
            KitchenStation::where('id', $data['station_id'])->where('store_id', $data['store_id'])->firstOrFail();
        } else {
            $data['station_id'] = null;
        }

        $data['owner_id'] = auth()->id();
        $data['password'] = Hash::make($data['password']);
        $data['active'] = true;

        $this->applyPin($data, $data['pin'] ?? null);

        StaffMember::create($data);

        if (function_exists('log_activity')) {
            log_activity('staff.created', ['email' => $data['email'], 'role' => $data['role']]);
        }

        return back()->with('success', ___('Staff member added.'));
    }

    public function update(Request $request, int $id)
    {
        $this->denyStaffSession();
        $staff = StaffMember::forOwner(auth()->id())->findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'role' => 'required|in:manager,cashier,kitchen,waiter',
            'pin' => 'nullable|digits_between:4,6',
            'active' => 'nullable|boolean',
            // P6-72: only the known permission keys, each a plain on/off flag.
            'permissions' => 'nullable|array:'.implode(',', StaffMember::PERMISSION_KEYS),
            'permissions.*' => 'boolean',
            'branch_id' => 'nullable|integer',
        ]);

        if (isset($data['permissions'])) {
            // BL-036 A2a: the sheet only shows switches for features the store has; overrides for
            // keys it did not show (feature off right now) are kept, not dropped.
            $kept = array_intersect_key((array) ($staff->permissions ?? []), array_flip(StaffMember::PERMISSION_KEYS));
            $data['permissions'] = array_merge($kept, array_map(fn ($v) => (bool) $v, $data['permissions']));
        }

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->input('password'));
            // P6-75: a new staff password signs the staff app out on every device.
            StaffToken::where('staff_id', $staff->id)->delete();
        }

        // #76: branch (if any) must belong to the staff member's restaurant.
        if (! empty($data['branch_id'])) {
            Branch::where('id', $data['branch_id'])->where('store_id', $staff->store_id)->firstOrFail();
        } else {
            $data['branch_id'] = null;
        }

        // S5: only touch the PIN when one was submitted; blank leaves it as-is.
        if ($request->filled('pin')) {
            $this->applyPin($data, $data['pin']);
        } else {
            unset($data['pin']);
        }

        // P6-220: switching a member on must fit the plan's staff limit; an owner on/off choice clears the plan mark.
        if (array_key_exists('active', $data) && $data['active'] !== null && (bool) $data['active'] !== (bool) $staff->active) {
            if ($data['active'] && ! PlanLimitSync::canActivate('staff', (int) $staff->store_id, auth()->user(), (int) $staff->id)) {
                return back()->withInput()->with('error', ___('Your plan allows :n active staff members. Switch another member off first, or upgrade your plan.', ['n' => (int) PlanLimitSync::limit('staff', auth()->user())]));
            }
            $data['plan_suspended_at'] = null;
        }

        // E12: audit before/after for a staff permission/role change.
        $before = ['role' => $staff->role, 'permissions' => $staff->permissions, 'active' => (bool) $staff->active];
        $staff->update($data);
        $fresh = $staff->fresh();
        $after = ['role' => $fresh->role, 'permissions' => $fresh->permissions, 'active' => (bool) $fresh->active];
        if (function_exists('log_activity') && $before != $after) {
            log_activity('staff.updated', $staff, ['old' => $before, 'new' => $after], (int) $staff->store_id);
        }

        return back()->with('success', ___('Staff member updated.'));
    }

    /**
     * P6-72: staff never manage staff. A staff dashboard session (owner-fenced web
     * guard) must not create members or grant permissions, even if a route map slip
     * let it reach here — only the owner, who holds every permission, may grant.
     */
    private function denyStaffSession(): void
    {
        abort_if(function_exists('is_staff_dashboard') && is_staff_dashboard(), 403);
    }

    /**
     * S5 / P6-88 — store the PIN only as a bcrypt pin_hash (+ offline verifier);
     * the legacy plaintext column is always nulled (no escape hatch).
     */
    private function applyPin(array &$data, $pin): void
    {
        if ($pin === null || $pin === '') {
            unset($data['pin']);

            return;
        }

        $data['pin_hash'] = Hash::make((string) $pin);
        $data['pin_offline_salt'] = bin2hex(random_bytes(16)); // offline POS PIN check (StaffMember::rememberOfflinePin)
        $data['pin_offline_kdf'] = hash_pbkdf2('sha256', (string) $pin, $data['pin_offline_salt'], StaffMember::OFFLINE_PIN_ROUNDS, 64);

        $data['pin'] = null; // P6-88: hash-only — the plaintext column is never written
    }

    /** HPMS E12(b): owner reset of a staff member's two-factor enrolment. */
    public function resetTwoFactor(int $id): RedirectResponse
    {
        $staff = StaffMember::forOwner(auth()->id())->findOrFail($id);
        StaffTwoFactorService::reset($staff);
        // P6-75: staff-app devices signed in under the old enrolment are signed out.
        StaffToken::where('staff_id', $staff->id)->delete();
        app(MobilePush::class)->revokeDevices(['staff_id' => (int) $staff->id]);
        if (function_exists('log_activity')) {
            log_activity('staff.two_factor_reset', $staff, [], (int) $staff->store_id);
        }

        return back()->with('success', ___('Two-factor authentication reset for this staff member.'));
    }

    /** P1-19b: owner revokes one passkey of one of their staff members (lost phone, staff left a shared device). */
    public function revokePasskey(int $id, int $passkeyId): RedirectResponse
    {
        abort_if(session()->has('quick_admin_user_id') || session()->has('impersonating'), 403);
        $staff = StaffMember::forOwner(auth()->id())->findOrFail($id);
        $passkey = $staff->passkeys()->whereKey($passkeyId)->firstOrFail();
        $passkey->delete();
        log_activity('staff.passkey_revoked', $passkey, ['staff_id' => $staff->id, 'name' => $passkey->name], (int) $staff->store_id);

        return back()->with('success', ___('Passkey removed.'));
    }

    public function destroy(int $id)
    {
        $staff = StaffMember::forOwner(auth()->id())->findOrFail($id);
        $staff->delete();

        return back()->with('success', ___('Staff member removed.'));
    }
}
