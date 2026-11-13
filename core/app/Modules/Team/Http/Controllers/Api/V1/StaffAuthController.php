<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Http\Controllers\Api\V1;

use App\Models\StaffMember;
use App\Services\StaffTwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Team\Models\StaffToken;

/**
 * Token-issuing auth for the staff app (POS / KDS / waiter). Two paths mirror the
 * dashboard: email + password, and a quick numeric PIN (for shared KDS tablets).
 * Staff are scoped per restaurant (store_id in the path).
 */
class StaffAuthController extends Controller
{
    /** POST /stores/{id}/staff/login — email + password. */
    public function login(Request $request, int $storeId)
    {
        Store::findOrFail($storeId);

        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device' => 'nullable|string|max:80',
            'code' => 'nullable|string|max:20', // E12(b): TOTP / recovery code
        ]);

        $staff = StaffMember::where('store_id', $storeId)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $data['email']))])
            ->where('active', true)
            ->first();

        if (! $staff || ! $staff->password || ! Hash::check($data['password'], $staff->password)) {
            return response()->json(['error' => 'Invalid staff credentials.'], 401);
        }

        // E12(b): TOTP challenge on EMAIL login when the staff member enrolled.
        // The PIN quick-switch (pin()) is deliberately exempt for shared devices.
        if ($staff->twoFactorEnabled()) {
            $code = (string) ($data['code'] ?? '');
            if ($code === '') {
                return response()->json(['error' => 'two_factor_required'], 401);
            }
            if (! StaffTwoFactorService::verify($staff, $code)) {
                return response()->json(['error' => 'invalid_two_factor'], 401);
            }
        }

        return $this->tokenResponse($staff, $data['device'] ?? null);
    }

    /** POST /stores/{id}/staff/pin — quick PIN login for shared devices. */
    public function pin(Request $request, int $storeId)
    {
        Store::findOrFail($storeId);

        $data = $request->validate([
            'pin' => 'required|digits_between:4,6',
            'device' => 'nullable|string|max:80',
        ]);

        // PINs are only unique within a restaurant; match via hashed dual-read.
        $staff = StaffMember::authenticateByPin($storeId, $data['pin']);

        if (! $staff) {
            if (StaffMember::pinLoginLocked($storeId)) {
                return response()->json(['error' => 'PIN login is locked for a few minutes. Sign in with email and password.'], 429);
            }

            return response()->json(['error' => 'Invalid PIN.'], 401);
        }

        return $this->tokenResponse($staff, $data['device'] ?? null);
    }

    private function tokenResponse(StaffMember $staff, ?string $device)
    {
        $raw = StaffToken::issue($staff, $device);
        $staff->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $raw,
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'role' => $staff->role,
                'store_id' => $staff->store_id,
                'permissions' => $this->permissionList($staff),
            ],
        ]);
    }

    private function permissionList(StaffMember $staff): array
    {
        $all = ['orders', 'menu_edit', 'coupons', 'analytics', 'kds', 'customers'];

        return array_values(array_filter($all, fn ($p) => $staff->hasPermission($p)));
    }
}
